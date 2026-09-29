<?php

namespace App\Domain\Crm;

use App\Models\Agreement;
use App\Models\Appointment;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\MarketingCampaign;
use App\Models\Setting;
use App\Models\User;

class DashboardService
{
    public function __construct(protected ScopeService $scopeService)
    {
    }

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
        $staleDays = (int) Setting::get('crm.stale_task_days', 5);

        $leadQuery = Lead::query();
        $leadQuery = $this->scopeService->applyLeadScope($leadQuery, $user);

        $totalLeads = (clone $leadQuery)->count();
        $convertedLeads = (clone $leadQuery)->where('status', Lead::STATUS_CONVERTED)->count();
        $openLeads = (clone $leadQuery)->open()->count();

        // Conversion rate: converted / total created (0 when no leads yet)
        $conversionRate = $totalLeads > 0 ? round($convertedLeads / $totalLeads * 100, 1) : 0.0;

        $funnel = (clone $leadQuery)
            ->selectRaw('status, COUNT(*) as cnt')
            ->groupBy('status')
            ->pluck('cnt', 'status')
            ->all();

        // Stale open leads: status not changed within threshold
        $staleLeads = (clone $leadQuery)
            ->open()
            ->where('status_updated_at', '<=', now()->subDays($staleDays))
            ->orderBy('status_updated_at')
            ->limit(10)
            ->get(['id', 'name', 'company', 'status', 'status_updated_at', 'owner_id']);

        // Upcoming appointments (next 7 days)
        $appointments = Appointment::whereBetween('scheduled_at', [now(), now()->addDays(7)])
            ->where('status', 'scheduled')
            ->when(!$user->isOrgLevel(), fn ($q) => $q->where('user_id', $user->id))
            ->count();

        // Checklist completion across customers in scope
        $checklist = $this->checklistStats($user);

        $campaigns = MarketingCampaign::query()
            ->when(!$user->isOrgLevel() && !$user->canApprove(), fn ($q) => $q->where('owner_id', $user->id))
            ->selectRaw('approval_status, COUNT(*) as cnt')
            ->groupBy('approval_status')
            ->pluck('cnt', 'approval_status')
            ->all();

        $pendingAgreements = Agreement::whereIn('status', ['sent', 'in_review', 'pending_signature'])->count();

        return [
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
                'count' => $staleLeads->count(),
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
        ];
    }

    protected function checklistStats(User $user): array
    {
        $customerQuery = Customer::query();
        $customerQuery = $this->scopeService->applyCustomerScope($customerQuery, $user);

        $customerIds = (clone $customerQuery)->pluck('id');

        if ($customerIds->isEmpty()) {
            return ['total_items' => 0, 'done_items' => 0, 'percent' => 0];
        }

        $total = \App\Models\ChecklistCompletion::whereIn('customer_id', $customerIds)->count();
        $done = \App\Models\ChecklistCompletion::whereIn('customer_id', $customerIds)->where('is_done', true)->count();

        return [
            'total_items' => $total,
            'done_items' => $done,
            'percent' => $total > 0 ? round($done / $total * 100) : 0,
        ];
    }
}
