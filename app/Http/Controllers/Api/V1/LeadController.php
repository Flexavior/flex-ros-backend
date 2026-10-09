<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Domain\Crm\CrmConfigLimits;
use App\Domain\Crm\LeadPicklistService;
use App\Domain\Crm\ScopeService;
use App\Models\CrmFieldDefinition;
use App\Models\Lead;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LeadController extends Controller
{
    public function __construct(
        protected ScopeService $scopeService,
        protected LeadPicklistService $picklists,
    ) {
    }

    public function index(Request $request)
    {
        $query = Lead::with(['owner:id,name', 'stage:id,code,name', 'customer:id,client_id,name'])
            ->orderByDesc('created_at');

        $query = $this->scopeService->applyLeadScope($query, $request->user());

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($currentStage = $request->query('current_stage')) {
            $query->where('current_stage', $currentStage);
        }
        if ($leadSource = $request->query('lead_source')) {
            $this->picklists->assertFilterValue('lead_source', (string) $leadSource);
            $query->where('lead_source', $leadSource);
        }
        if ($segment = $request->query('customer_segment')) {
            $this->picklists->assertFilterValue('customer_segment', (string) $segment);
            $query->where('customer_segment', $segment);
        }
        if ($industry = $request->query('industry')) {
            $this->picklists->assertFilterValue('industry', (string) $industry);
            $query->where('industry', $industry);
        }
        if ($geo = $request->query('geo_location')) {
            $this->picklists->assertFilterValue('geo_location', (string) $geo);
            $query->where('geo_location', $geo);
        }
        if ($request->boolean('stale')) {
            $days = (int) (\App\Models\Setting::get('crm.stale_task_days', 5));
            $query->stale($days);
        }

        $perPage = min(
            max($request->integer('per_page', CrmConfigLimits::LIST_PER_PAGE_DEFAULT), 1),
            CrmConfigLimits::LIST_PER_PAGE_MAX
        );
        $page = max(1, min($request->integer('page', 1), CrmConfigLimits::LIST_PAGE_MAX));

        return response()->json($query->paginate($perPage, ['*'], 'page', $page));
    }

    public function schema()
    {
        return response()->json([
            'picklists' => $this->picklists->all(),
            'custom_fields' => [
                'lead' => CrmFieldDefinition::where('entity', 'lead')->where('is_active', true)->orderBy('sort_order')->get(),
                'engagement' => CrmFieldDefinition::where('entity', 'engagement')->where('is_active', true)->orderBy('sort_order')->get(),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'company' => 'nullable|string|max:255',
            'email' => 'nullable|email',
            'phone' => 'nullable|string|max:50',
            'source' => 'nullable|string|max:50',
            'lead_source' => $this->picklists->rule('lead_source'),
            'product_interest' => $this->picklists->rule('product_interest'),
            'customer_segment' => $this->picklists->rule('customer_segment'),
            'geo_location' => $this->picklists->rule('geo_location'),
            'industry' => $this->picklists->rule('industry'),
            'contact_role' => $this->picklists->rule('contact_role'),
            'current_stage' => $this->picklists->rule('current_stage'),
            'interest_level' => $this->picklists->rule('interest_level'),
            'buying_timeline' => $this->picklists->rule('buying_timeline'),
            'primary_contact_method' => $this->picklists->rule('contact_method'),
            'last_activity_outcome' => $this->picklists->rule('activity_outcome'),
            'custom_fields' => 'nullable|array',
            'notes' => 'nullable|string|max:'.CrmConfigLimits::NOTES_MAX,
            'owner_id' => 'nullable|exists:users,id',
            'status' => 'nullable|in:new,contacted,qualified,appointment,converted,lost',
        ]);
        $this->validateCustomFieldValues($data['custom_fields'] ?? null, 'lead');
        $this->syncLegacyStatusColumns($data);

        // Only supervisors+ can assign to someone else; sales always own their leads
        $user = $request->user();
        if (isset($data['owner_id']) && $data['owner_id'] != $user->id && !$user->canApprove()) {
            unset($data['owner_id']);
        }

        // Default ownership to the creator when no owner was supplied (or assignment was denied)
        $data['owner_id'] ??= $user->id;

        $lead = Lead::create($data);

        return response()->json($lead->load('owner:id,name'), 201);
    }

    public function show(Request $request, Lead $lead)
    {
        abort_unless($this->scopeService->canAccessLead($request->user(), $lead), 403);

        return response()->json($lead->load([
            'owner:id,name', 'stage:id,code,name', 'customer:id,client_id,name',
            'engagements.user:id,name', 'engagements.assignedOwner:id,name', 'appointments.user:id,name',
        ]));
    }

    public function update(Request $request, Lead $lead)
    {
        abort_unless($this->scopeService->canAccessLead($request->user(), $lead), 403);

        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'company' => 'nullable|string|max:255',
            'email' => 'nullable|email',
            'phone' => 'nullable|string|max:50',
            'source' => 'nullable|string|max:50',
            'lead_source' => $this->picklists->rule('lead_source', true),
            'product_interest' => $this->picklists->rule('product_interest', true),
            'customer_segment' => $this->picklists->rule('customer_segment', true),
            'geo_location' => $this->picklists->rule('geo_location', true),
            'industry' => $this->picklists->rule('industry', true),
            'contact_role' => $this->picklists->rule('contact_role', true),
            'current_stage' => $this->picklists->rule('current_stage', true),
            'interest_level' => $this->picklists->rule('interest_level', true),
            'buying_timeline' => $this->picklists->rule('buying_timeline', true),
            'primary_contact_method' => $this->picklists->rule('contact_method', true),
            'last_activity_outcome' => $this->picklists->rule('activity_outcome', true),
            'custom_fields' => 'nullable|array',
            'notes' => 'nullable|string|max:'.CrmConfigLimits::NOTES_MAX,
            'status' => 'sometimes|in:new,contacted,qualified,appointment,converted,lost',
            'owner_id' => 'sometimes|exists:users,id',
        ]);
        $this->validateCustomFieldValues($data['custom_fields'] ?? null, 'lead');
        $this->syncLegacyStatusColumns($data);

        $user = $request->user();
        if (isset($data['owner_id']) && $data['owner_id'] != $user->id && !$user->canApprove()) {
            unset($data['owner_id']);
        }

        $lead->update($data);

        return response()->json($lead->fresh(['owner:id,name']));
    }

    public function destroy(Request $request, Lead $lead)
    {
        abort_unless($this->scopeService->canAccessLead($request->user(), $lead), 403);

        $lead->delete();

        return response()->json(['message' => 'Lead deleted.'], 200);
    }

    /** Log a follow-up engagement; refreshes stale timer on the lead. */
    public function storeEngagement(Request $request, Lead $lead)
    {
        abort_unless($this->scopeService->canAccessLead($request->user(), $lead), 403);

        $data = $request->validate([
            'channel' => 'nullable|string|max:50',
            'summary' => 'nullable|string|max:'.CrmConfigLimits::ENGAGEMENT_SUMMARY_MAX,
            'occurred_at' => 'nullable|date',
            'contact_method' => $this->picklists->rule('contact_method'),
            'contact_person' => 'nullable|string|max:150',
            'purpose' => 'nullable|string|max:150',
            'activity_outcome' => $this->picklists->rule('activity_outcome'),
            'customer_response' => 'nullable|string|max:'.CrmConfigLimits::NOTES_MAX,
            'next_action' => 'nullable|string|max:500',
            'next_action_at' => 'nullable|date',
            'next_follow_up_at' => 'nullable|date',
            'assigned_owner_id' => 'nullable|exists:users,id',
            'completed' => 'nullable|boolean',
            'notes' => 'nullable|string|max:'.CrmConfigLimits::NOTES_MAX,
            'custom_fields' => 'nullable|array',
        ]);
        $this->validateCustomFieldValues($data['custom_fields'] ?? null, 'engagement');

        $summary = $data['summary'] ?? $data['purpose'] ?? null;
        if (!$summary) {
            throw ValidationException::withMessages([
                'summary' => ['Provide a summary or purpose for this follow-up entry.'],
            ]);
        }

        $engagement = $lead->engagements()->create([
            'user_id' => $request->user()->id,
            'channel' => $data['channel'] ?? null,
            'summary' => $summary,
            'occurred_at' => $data['occurred_at'] ?? now(),
            'contact_method' => $data['contact_method'] ?? null,
            'contact_person' => $data['contact_person'] ?? null,
            'purpose' => $data['purpose'] ?? null,
            'activity_outcome' => $data['activity_outcome'] ?? null,
            'customer_response' => $data['customer_response'] ?? null,
            'next_action' => $data['next_action'] ?? null,
            'next_action_at' => $data['next_action_at'] ?? null,
            'next_follow_up_at' => $data['next_follow_up_at'] ?? null,
            'assigned_owner_id' => $data['assigned_owner_id'] ?? null,
            'completed' => $data['completed'] ?? false,
            'notes' => $data['notes'] ?? null,
            'custom_fields' => $data['custom_fields'] ?? null,
            'status_updated_at' => now(),
        ]);

        $lead->forceFill([
            'status_updated_at' => now(),
            'last_activity_outcome' => $data['activity_outcome'] ?? $lead->last_activity_outcome,
        ])->save();

        return response()->json($engagement, 201);
    }

    /** Convert lead -> customer (generates Client ID + maps products + checklists). */
    public function convert(Request $request, Lead $lead)
    {
        abort_unless($this->scopeService->canAccessLead($request->user(), $lead), 403);

        $data = $request->validate([
            'product_service_ids' => 'nullable|array',
            'product_service_ids.*' => 'integer|exists:products_services,id',
            'client_id' => 'nullable|string|max:30',
        ]);

        $this->authorizeConvert($request->user());

        $service = app(\App\Domain\Crm\LeadConversionService::class);

        try {
            $customer = $service->convert(
                $lead,
                $data['product_service_ids'] ?? [],
                $request->user()->id,
                $data['client_id'] ?? null,
            );
        } catch (\InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        }

        return response()->json($customer, 201);
    }

    protected function authorizeConvert(User $user): void
    {
        // Staff role may not convert; sales/senior staff/supervisor+ can
        if ($user->hasRole(\App\Models\Role::STAFF) || $user->hasRole(\App\Models\Role::MARKETING)) {
            abort(403, 'Your role cannot convert leads.');
        }
    }

    private function validateCustomFieldValues(?array $values, string $entity): void
    {
        if ($values === null) {
            return;
        }

        $defs = CrmFieldDefinition::where('entity', $entity)->where('is_active', true)->get()->keyBy('field_key');
        foreach ($values as $fieldKey => $value) {
            $def = $defs->get($fieldKey);
            if (!$def) {
                throw ValidationException::withMessages([
                    "custom_fields.{$fieldKey}" => ['Unknown custom field key.'],
                ]);
            }

            if ($value === null || $value === '') {
                continue;
            }

            if ($def->field_type === 'boolean' && !is_bool($value)) {
                throw ValidationException::withMessages(["custom_fields.{$fieldKey}" => ['Expected boolean value.']]);
            }
            if ($def->field_type === 'date' && !strtotime((string) $value)) {
                throw ValidationException::withMessages(["custom_fields.{$fieldKey}" => ['Expected a valid date value.']]);
            }
            if ($def->field_type === 'select') {
                $options = collect($def->options ?? [])->map(fn ($v) => (string) $v)->all();
                if (!in_array((string) $value, $options, true)) {
                    throw ValidationException::withMessages(["custom_fields.{$fieldKey}" => ['Value is not in allowed options.']]);
                }
            }
        }
    }

    private function syncLegacyStatusColumns(array &$data): void
    {
        $stageToStatus = [
            'new' => 'new',
            'contacted' => 'contacted',
            'qualified' => 'qualified',
            'demo / meeting' => 'appointment',
            'proposal' => 'qualified',
            'negotiation' => 'qualified',
            'won' => 'converted',
            'lost' => 'lost',
        ];
        $statusToStage = [
            'new' => 'New',
            'contacted' => 'Contacted',
            'qualified' => 'Qualified',
            'appointment' => 'Demo / Meeting',
            'converted' => 'Won',
            'lost' => 'Lost',
        ];

        if (!empty($data['current_stage']) && empty($data['status'])) {
            $status = $stageToStatus[Str::lower((string) $data['current_stage'])] ?? null;
            if ($status) {
                $data['status'] = $status;
            }
        }

        if (!empty($data['status']) && empty($data['current_stage'])) {
            $data['current_stage'] = $statusToStage[$data['status']] ?? null;
        }

        if (!empty($data['source']) && empty($data['lead_source'])) {
            $data['lead_source'] = $data['source'];
        }
        if (!empty($data['lead_source'])) {
            $data['source'] = $data['lead_source'];
        }
    }
}
