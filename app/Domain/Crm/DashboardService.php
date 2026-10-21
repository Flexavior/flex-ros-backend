<?php

namespace App\Domain\Crm;

use App\Models\Agreement;
use App\Models\Appointment;
use App\Models\Customer;
use App\Models\Engagement;
use App\Models\Lead;
use App\Models\MarketingCampaign;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class DashboardService
{
    public function __construct(
        protected ScopeService $scopeService,
        protected DashboardPersonaResolver $personaResolver,
    ) {}

    /**
     * Org/team/scope-aware CRM metrics:
     * - conversion rate
     * - pipeline funnel
     * - stale tasks (no change for N days — settings-driven, default 5)
     * - appointments this week
     * - checklist completion
     * - marketing campaign stats
     */
    public function metrics(User $user): array
    {
        $now = now();
        $staleDays = (int) Setting::get('crm.stale_task_days', 5);
        $dashboardConfig = DashboardConfig::all();
        $persona = $this->personaResolver->resolve($user);

        $leadQuery = Lead::query();
        $leadQuery = $this->scopeService->applyLeadScope($leadQuery, $user);

        $totalLeads = (clone $leadQuery)->count();
        $convertedLeads = (clone $leadQuery)->where('status', Lead::STATUS_CONVERTED)->count();
        $openLeads = (clone $leadQuery)->open()->count();

        // Conversion rate: converted / total created (0 when no leads yet)
        $conversionRate = $totalLeads > 0 ? round($convertedLeads / $totalLeads * 100, 1) : 0.0;

        // Pipeline funnel by picklist stage (not legacy status slug).
        $funnel = (clone $leadQuery)
            ->selectRaw("COALESCE(NULLIF(TRIM(current_stage), ''), 'Unstaged') AS stage_name, COUNT(*) AS aggregate")
            ->groupBy('stage_name')
            ->orderBy('stage_name')
            ->pluck('aggregate', 'stage_name')
            ->map(static fn ($count) => (int) $count)
            ->all();

        $staleBase = (clone $leadQuery)
            ->open()
            ->where('status_updated_at', '<=', $now->copy()->subDays($staleDays));
        $staleCount = (clone $staleBase)->count();
        $staleLeads = (clone $staleBase)
            ->with('owner:id,name')
            ->orderBy('status_updated_at')
            ->limit(10)
            ->get(['id', 'name', 'company', 'status', 'status_updated_at', 'owner_id']);

        $appointmentBase = Appointment::query()
            ->whereIn('lead_id', (clone $leadQuery)->select('id'))
            ->whereBetween('scheduled_at', [$now, $now->copy()->addDays(7)])
            ->where('status', 'scheduled')
            ->when(! $user->isOrgLevel(), fn ($query) => $query->whereIn('user_id', $this->visibleUserIds($user)));
        $appointments = (clone $appointmentBase)->count();

        // Checklist completion across customers in scope
        $checklist = $this->checklistStats($user);

        $campaigns = MarketingCampaign::query()
            ->when(!$user->isOrgLevel() && !$user->canApprove(), fn ($q) => $q->where('owner_id', $user->id))
            ->selectRaw('approval_status, COUNT(*) as cnt')
            ->groupBy('approval_status')
            ->pluck('cnt', 'approval_status')
            ->all();

        $customerQuery = $this->scopeService->applyCustomerScope(Customer::query(), $user);
        $pendingAgreementBase = Agreement::query()
            ->whereIn('customer_id', (clone $customerQuery)->select('id'))
            ->whereIn('status', ['sent', 'in_review', 'pending_signature']);
        $pendingAgreements = (clone $pendingAgreementBase)->count();

        $maxIdle = Lead::qualifyMaxIdleTouches();
        $qualifyBase = (clone $leadQuery)->open()->withQualifyIdleMetrics()
            ->having('idle_touch_count', '>=', $maxIdle);
        $qualifyCount = (clone $qualifyBase)->count();
        $qualifySample = (clone $qualifyBase)
            ->with('owner:id,name')
            ->orderByDesc('idle_touch_count')
            ->limit(8)
            ->get(['id', 'name', 'company', 'owner_id'])
            ->map(fn (Lead $l) => [
                'id' => $l->id,
                'name' => $l->name,
                'company' => $l->company,
                'owner_id' => $l->owner_id,
                'owner_name' => $l->owner?->name,
                'idle_touch_count' => $l->idle_touch_count,
            ])->all();

        $currentQuarter = [
            $now->copy()->startOfQuarter(),
            $now->copy(),
        ];
        $elapsedSeconds = $currentQuarter[0]->diffInSeconds($currentQuarter[1]);
        $previousStart = $currentQuarter[0]->copy()->subQuarter()->startOfQuarter();
        $previousEnd = $previousStart->copy()->addSeconds($elapsedSeconds);
        if ($previousEnd->greaterThan($previousStart->copy()->endOfQuarter())) {
            $previousEnd = $previousStart->copy()->endOfQuarter();
        }
        $previousQuarter = [
            $previousStart,
            $previousEnd,
        ];
        $yearStart = $currentQuarter[0]->copy()->subYear();
        $yearEnd = $yearStart->copy()->addSeconds($elapsedSeconds);
        if ($yearEnd->greaterThan($yearStart->copy()->endOfQuarter())) {
            $yearEnd = $yearStart->copy()->endOfQuarter();
        }
        $previousYear = [
            $yearStart,
            $yearEnd,
        ];

        $includeRevenue = in_array('revenue', $persona['sections'], true);
        $currentPeriod = $this->periodMetrics($leadQuery, $customerQuery, $dashboardConfig, $currentQuarter[0], $currentQuarter[1], $includeRevenue);
        $previousPeriod = $this->periodMetrics($leadQuery, $customerQuery, $dashboardConfig, $previousQuarter[0], $previousQuarter[1], $includeRevenue);
        $yearPeriod = $this->periodMetrics($leadQuery, $customerQuery, $dashboardConfig, $previousYear[0], $previousYear[1], $includeRevenue);

        $mqlCount = $currentPeriod['mql'];
        $sqlCount = $currentPeriod['sql'];
        $mqlSqlRatio = $mqlCount > 0 ? round($sqlCount / $mqlCount * 100, 1) : 0.0;
        $pipelineCount = $includeRevenue
            ? (clone $leadQuery)
                ->open()
                ->whereIn('current_stage', $dashboardConfig[DashboardConfig::PIPELINE_STAGES_KEY])
                ->count()
            : 0;

        $repActivity = in_array('rep_activity', $persona['sections'], true)
            ? $this->repActivityThisWeek($leadQuery, $user, $now->copy()->startOfWeek(), $now->copy()->endOfWeek())
            : [];
        $actionItems = $this->actionItems(
            $persona['sections'],
            $staleLeads,
            $qualifySample,
            $pendingAgreementBase,
            $appointmentBase,
            $user
        );

        return [
            'persona' => $persona['persona'],
            'sections' => $persona['sections'],
            'period' => [
                'label' => 'Current quarter to date',
                'start' => $currentQuarter[0]->toIso8601String(),
                'end' => $currentQuarter[1]->toIso8601String(),
            ],
            'comparisons' => [
                'qoq' => $this->comparison($currentPeriod, $previousPeriod, $previousQuarter),
                'yoy' => $this->comparison($currentPeriod, $yearPeriod, $previousYear),
            ],
            'action_items' => $actionItems,
            'action_items_count' => count($actionItems),
            'rep_activity_this_week' => $repActivity,
            'mql_sql' => [
                'mql' => $mqlCount,
                'sql' => $sqlCount,
                'ratio_pct' => $mqlSqlRatio,
                'period' => 'current_quarter_to_date',
                'mql_stages' => $dashboardConfig[DashboardConfig::MQL_STAGES_KEY],
                'sql_stages' => $dashboardConfig[DashboardConfig::SQL_STAGES_KEY],
            ],
            'revenue' => [
                'signed' => [
                    'amount' => $currentPeriod['signed_revenue'],
                    'count' => $currentPeriod['signed_agreements'],
                    'period' => 'current_quarter_to_date',
                ],
                'pipeline' => [
                    'lead_count' => $pipelineCount,
                    'stages' => $dashboardConfig[DashboardConfig::PIPELINE_STAGES_KEY],
                ],
            ],
            'stale_task_days' => $staleDays,
            'conversion_rate' => $conversionRate,
            'leads' => [
                'total' => $totalLeads,
                'open' => $openLeads,
                'converted' => $convertedLeads,
            ],
            'funnel' => $funnel,
            'stale_tasks' => [
                'threshold_days' => $staleDays,
                'count' => $staleCount,
                'items' => $staleLeads->map(fn ($l) => [
                    'id' => $l->id,
                    'name' => $l->name,
                    'company' => $l->company,
                    'status' => $l->status,
                    'days_no_change' => (int) round(now()->diffInDays($l->status_updated_at)),
                ])->all(),
            ],
            'appointments_this_week' => $appointments,
            'checklist' => $checklist,
            'marketing' => [
                'campaigns' => $campaigns,
                'pending_approvals' => $campaigns['pending'] ?? 0,
            ],
            'pending_agreements' => $pendingAgreements,
            'qualify_review' => [
                'count' => $qualifyCount,
                'max_idle_touches' => $maxIdle,
                'items' => $qualifySample,
            ],
            'generated_at' => $now->toIso8601String(),
        ];
    }

    /**
     * @param array<string, list<string>> $config
     * @return array<string, int|float>
     */
    protected function periodMetrics(
        Builder $leadQuery,
        Builder $customerQuery,
        array $config,
        mixed $start,
        mixed $end,
        bool $includeRevenue
    ): array
    {
        $createdInPeriod = (clone $leadQuery)->whereBetween('created_at', [$start, $end]);
        $signedAgreements = 0;
        $signedRevenue = 0.0;
        if ($includeRevenue) {
            $agreementQuery = Agreement::query()
                ->whereIn('customer_id', (clone $customerQuery)->select('id'))
                ->where('status', 'signed')
                ->whereBetween('signed_at', [$start, $end]);
            $signedAgreements = (clone $agreementQuery)->count();
            $signedRevenue = round((float) (clone $agreementQuery)->sum('value'), 2);
        }

        $activityQuery = Engagement::query()
            ->whereIn('lead_id', (clone $leadQuery)->select('id'))
            ->where(function ($query) use ($start, $end) {
                $query->whereBetween('occurred_at', [$start, $end])
                    ->orWhere(function ($fallback) use ($start, $end) {
                        $fallback->whereNull('occurred_at')->whereBetween('created_at', [$start, $end]);
                    });
            });

        return [
            'leads_created' => (clone $createdInPeriod)->count(),
            'converted_leads' => (clone $leadQuery)
                ->where('status', Lead::STATUS_CONVERTED)
                ->whereBetween('status_updated_at', [$start, $end])
                ->count(),
            'mql' => (clone $createdInPeriod)->whereIn('current_stage', $config[DashboardConfig::MQL_STAGES_KEY])->count(),
            'sql' => (clone $createdInPeriod)->whereIn('current_stage', $config[DashboardConfig::SQL_STAGES_KEY])->count(),
            'activity_total' => $activityQuery->count(),
            'signed_agreements' => $signedAgreements,
            'signed_revenue' => $signedRevenue,
        ];
    }

    /** @return array<string, mixed> */
    protected function comparison(array $current, array $previous, array $previousWindow): array
    {
        $result = [
            'previous_period' => [
                'start' => $previousWindow[0]->toIso8601String(),
                'end' => $previousWindow[1]->toIso8601String(),
            ],
        ];

        foreach ($current as $key => $value) {
            $prior = $previous[$key] ?? 0;
            $result[$key] = [
                'current' => $value,
                'previous' => $prior,
                'delta_pct' => $this->deltaPercent((float) $value, (float) $prior),
            ];
        }

        return $result;
    }

    protected function deltaPercent(float $current, float $previous): float
    {
        if ($previous == 0.0) {
            return $current == 0.0 ? 0.0 : 100.0;
        }

        return round(($current - $previous) / abs($previous) * 100, 1);
    }

    /** @return list<array{user_id:int,name:string,activity_count:int}> */
    protected function repActivityThisWeek(Builder $leadQuery, User $user, mixed $start, mixed $end): array
    {
        $reps = User::query()
            ->where('is_active', true)
            ->whereHas('role', fn ($query) => $query->whereIn('code', Role::DASHBOARD_REP_ACTIVITY))
            ->when(! $user->isOrgLevel(), fn ($query) => $query->whereIn('id', $this->visibleUserIds($user)))
            ->orderBy('name')
            ->get(['id', 'name']);

        if ($reps->isEmpty()) {
            return [];
        }

        $counts = Engagement::query()
            ->whereIn('engagements.lead_id', (clone $leadQuery)->select('id'))
            ->whereIn('engagements.user_id', $reps->pluck('id'))
            ->where(function ($query) use ($start, $end) {
                $query->whereBetween('engagements.occurred_at', [$start, $end])
                    ->orWhere(function ($fallback) use ($start, $end) {
                        $fallback->whereNull('engagements.occurred_at')
                            ->whereBetween('engagements.created_at', [$start, $end]);
                    });
            })
            ->selectRaw('engagements.user_id, COUNT(*) AS activity_count')
            ->groupBy('engagements.user_id')
            ->get()
            ->pluck('activity_count', 'user_id');

        return $reps
            ->map(static fn (User $rep) => [
                'user_id' => (int) $rep->id,
                'name' => $rep->name,
                'activity_count' => (int) ($counts[$rep->id] ?? 0),
            ])
            ->sortByDesc('activity_count')
            ->values()
            ->all();
    }

    /** @return list<int> */
    protected function visibleUserIds(User $user): array
    {
        return match ($user->role?->code) {
            Role::SUPERVISOR => $user->team_id
                ? User::query()->where('team_id', $user->team_id)->pluck('id')->map(static fn ($id) => (int) $id)->all()
                : [$user->id],
            Role::SENIOR_STAFF => array_values(array_map(
                'intval',
                array_merge([$user->id], $user->subordinates()->pluck('id')->all())
            )),
            default => [$user->id],
        };
    }

    /** @return list<array<string, mixed>> */
    protected function actionItems(
        array $sections,
        mixed $staleLeads,
        array $qualifySample,
        Builder $pendingAgreementBase,
        Builder $appointmentBase,
        User $user
    ): array {
        if (! in_array('actions', $sections, true)) {
            return [];
        }

        $items = [];
        if ($user->hasAnyRole(\App\Models\Role::SALES_CRM)) {
            foreach ($staleLeads as $lead) {
                $items[] = [
                    'id' => "stale-lead-{$lead->id}",
                    'kind' => 'stale_lead',
                    'title' => "{$lead->name} has not moved",
                    'entity_type' => 'lead',
                    'entity_id' => $lead->id,
                    'entity_label' => $lead->name,
                    'assignee_name' => $lead->owner?->name ?? 'Unassigned',
                    'route' => "/leads/{$lead->id}",
                    'priority' => 'high',
                    'meta' => ['days_no_change' => (int) round(now()->diffInDays($lead->status_updated_at))],
                ];
            }

            foreach ($qualifySample as $lead) {
                $items[] = [
                    'id' => "qualify-lead-{$lead['id']}",
                    'kind' => 'qualify_review',
                    'title' => "{$lead['name']} needs qualification progress",
                    'entity_type' => 'lead',
                    'entity_id' => $lead['id'],
                    'entity_label' => $lead['name'],
                    'assignee_name' => $lead['owner_name'] ?? 'Unassigned',
                    'route' => "/leads/{$lead['id']}",
                    'priority' => 'medium',
                    'meta' => ['idle_touch_count' => $lead['idle_touch_count']],
                ];
            }

            foreach ((clone $pendingAgreementBase)->with('customer:id,name,company,owner_id', 'customer.owner:id,name')->oldest()->limit(5)->get() as $agreement) {
                if (! $agreement->customer) {
                    continue;
                }
                $items[] = [
                    'id' => "agreement-{$agreement->id}",
                    'kind' => 'pending_agreement',
                    'title' => "{$agreement->title} needs follow-up",
                    'entity_type' => 'customer',
                    'entity_id' => $agreement->customer_id,
                    'entity_label' => $agreement->customer->name,
                    'assignee_name' => $agreement->customer->owner?->name ?? 'Unassigned',
                    'route' => "/customers/{$agreement->customer_id}",
                    'priority' => 'medium',
                    'meta' => ['status' => $agreement->status],
                ];
            }

            foreach ((clone $appointmentBase)->with(['lead:id,name,company,owner_id', 'lead.owner:id,name', 'user:id,name'])->orderBy('scheduled_at')->limit(5)->get() as $appointment) {
                $assignee = $appointment->user?->name ?? $appointment->lead?->owner?->name ?? 'Unassigned';
                $items[] = [
                    'id' => "appointment-{$appointment->id}",
                    'kind' => 'upcoming_appointment',
                    'title' => $appointment->title,
                    'entity_type' => 'lead',
                    'entity_id' => $appointment->lead_id,
                    'entity_label' => $appointment->lead?->name ?? $appointment->title,
                    'assignee_name' => $assignee,
                    'route' => "/leads/{$appointment->lead_id}",
                    'priority' => 'normal',
                    'meta' => ['scheduled_at' => $appointment->scheduled_at?->toIso8601String()],
                ];
            }
        }

        if (in_array('marketing', $sections, true)) {
            $campaignQuery = MarketingCampaign::query()
                ->with('owner:id,name')
                ->where('approval_status', 'pending')
                ->when(! $user->isOrgLevel() && ! $user->canApprove(), fn ($query) => $query->where('owner_id', $user->id));
            foreach ($campaignQuery->oldest()->limit(5)->get(['id', 'name', 'owner_id']) as $campaign) {
                $items[] = [
                    'id' => "campaign-{$campaign->id}",
                    'kind' => 'campaign_approval',
                    'title' => "{$campaign->name} is pending approval",
                    'entity_type' => 'campaign',
                    'entity_id' => $campaign->id,
                    'entity_label' => $campaign->name,
                    'assignee_name' => $campaign->owner?->name ?? 'Unassigned',
                    'route' => '/marketing',
                    'priority' => 'medium',
                    'meta' => [],
                ];
            }
        }

        $priority = ['high' => 0, 'medium' => 1, 'normal' => 2];
        usort($items, static fn ($a, $b) => ($priority[$a['priority']] ?? 9) <=> ($priority[$b['priority']] ?? 9));

        return array_slice($items, 0, 20);
    }

    protected function checklistStats(User $user): array
    {
        $customerQuery = Customer::query();
        $customerQuery = $this->scopeService->applyCustomerScope($customerQuery, $user);

        $stats = \App\Models\ChecklistCompletion::query()
            ->whereIn('customer_id', (clone $customerQuery)->select('id'))
            ->selectRaw('COUNT(*) AS total_items, SUM(CASE WHEN is_done = 1 THEN 1 ELSE 0 END) AS done_items')
            ->first();
        $total = (int) ($stats?->total_items ?? 0);
        $done = (int) ($stats?->done_items ?? 0);

        return [
            'total_items' => $total,
            'done_items' => $done,
            'percent' => $total > 0 ? round($done / $total * 100) : 0,
        ];
    }
}
