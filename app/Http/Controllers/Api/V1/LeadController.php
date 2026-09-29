<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Domain\Crm\ScopeService;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Http\Request;

class LeadController extends Controller
{
    public function __construct(protected ScopeService $scopeService)
    {
    }

    public function index(Request $request)
    {
        $query = Lead::with(['owner:id,name', 'stage:id,code,name', 'customer:id,client_id,name'])
            ->orderByDesc('created_at');

        $query = $this->scopeService->applyLeadScope($query, $request->user());

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($request->boolean('stale')) {
            $days = (int) (\App\Models\Setting::get('crm.stale_task_days', 5));
            $query->stale($days);
        }

        return response()->json($query->paginate($request->integer('per_page', 25)));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'company' => 'nullable|string|max:255',
            'email' => 'nullable|email',
            'phone' => 'nullable|string|max:50',
            'source' => 'nullable|string|max:50',
            'notes' => 'nullable|string',
            'owner_id' => 'nullable|exists:users,id',
            'status' => 'nullable|in:new,contacted,qualified,appointment,converted,lost',
        ]);

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
            'engagements.user:id,name', 'appointments.user:id,name',
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
            'notes' => 'nullable|string',
            'status' => 'sometimes|in:new,contacted,qualified,appointment,converted,lost',
            'owner_id' => 'sometimes|exists:users,id',
        ]);

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
            'summary' => 'required|string',
            'next_action' => 'nullable|string',
            'next_action_at' => 'nullable|date',
        ]);

        $engagement = $lead->engagements()->create([
            'user_id' => $request->user()->id,
            'channel' => $data['channel'] ?? null,
            'summary' => $data['summary'],
            'next_action' => $data['next_action'] ?? null,
            'next_action_at' => $data['next_action_at'] ?? null,
            'status_updated_at' => now(),
        ]);

        $lead->forceFill(['status_updated_at' => now()])->save();

        return response()->json($engagement, 201);
    }

    /** Convert lead -> customer (generates Client ID + maps products + checklists). */
    public function convert(Request $request, Lead $lead)
    {
        abort_unless($this->scopeService->canAccessLead($request->user(), $lead), 403);

        $data = $request->validate([
            'product_service_ids' => 'nullable|array',
            'product_service_ids.*' => 'integer|exists:products_services,id',
        ]);

        $this->authorizeConvert($request->user());

        $service = app(\App\Domain\Crm\LeadConversionService::class);

        try {
            $customer = $service->convert($lead, $data['product_service_ids'] ?? [], $request->user()->id);
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
}
