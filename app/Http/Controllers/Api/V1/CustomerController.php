<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Domain\Crm\ChecklistService;
use App\Domain\Crm\ClientIdGenerator;
use App\Domain\Crm\CrmConfigLimits;
use App\Domain\Crm\CustomerStatus;
use App\Domain\Crm\LeadPicklistService;
use App\Domain\Crm\ScopeService;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CustomerController extends Controller
{
    public function __construct(
        protected ScopeService $scopeService,
        protected ChecklistService $checklistService,
        protected LeadPicklistService $picklists,
    ) {
    }

    public function index(Request $request)
    {
        $query = Customer::with(['owner:id,name', 'products:id,name,code'])
            ->orderByDesc('created_at');

        $query = $this->scopeService->applyCustomerScope($query, $request->user());

        $perPage = min(
            max($request->integer('per_page', CrmConfigLimits::LIST_PER_PAGE_DEFAULT), 1),
            CrmConfigLimits::LIST_PER_PAGE_MAX
        );
        $page = max(1, min($request->integer('page', 1), CrmConfigLimits::LIST_PAGE_MAX));

        return response()->json($query->paginate($perPage, ['*'], 'page', $page));
    }

    public function show(Request $request, Customer $customer)
    {
        $this->authorizeView($request, $customer);

        return response()->json([
            'customer' => $customer->load(['owner:id,name', 'products', 'agreements', 'launchPlans.dependencies', 'lead:id,name,industry,geo_location,customer_segment']),
            'checklists' => $this->checklistService->completionFor($customer),
        ]);
    }

    public function update(Request $request, Customer $customer, ClientIdGenerator $clientIds)
    {
        $this->authorizeView($request, $customer);

        if ($request->has('status')) {
            $request->merge(['status' => CustomerStatus::normalize($request->input('status'))]);
        }
        foreach (['customer_segment', 'industry', 'geo_location'] as $pick) {
            if ($request->has($pick) && $request->input($pick) === '') {
                $request->merge([$pick => null]);
            }
        }

        $data = $request->validate([
            'client_id' => 'sometimes|string|max:30',
            'name' => 'sometimes|string|max:255',
            'company' => 'nullable|string|max:255',
            'email' => 'nullable|email',
            'phone' => 'nullable|string|max:50',
            'address' => 'nullable|string|max:'.CrmConfigLimits::CUSTOMER_ADDRESS_MAX,
            'status' => ['sometimes', 'string', Rule::in(CustomerStatus::CANONICAL)],
            'customer_segment' => ['nullable', 'string', 'max:120', $this->picklists->rule('customer_segment')],
            'industry' => ['nullable', 'string', 'max:120', $this->picklists->rule('industry')],
            'geo_location' => ['nullable', 'string', 'max:120', $this->picklists->rule('geo_location')],
        ]);

        if (isset($data['client_id']) && $data['client_id'] !== $customer->client_id) {
            $clientIds->validateNewFormat($data['client_id']);
            $clientIds->assertUnique($data['client_id'], $customer->id);
        }

        $customer->fill($data);
        $customer->save();

        return response()->json($customer->load(['owner:id,name', 'products']));
    }

    /** Toggle a dynamic checklist item for this customer. */
    public function toggleChecklist(Request $request, Customer $customer, int $itemId)
    {
        $this->authorizeView($request, $customer);

        $data = $request->validate([
            'is_done' => 'required|boolean',
            'note' => 'nullable|string',
        ]);

        $completion = $this->checklistService->setItemDone(
            $customer,
            $itemId,
            $data['is_done'],
            $data['note'] ?? null
        );

        return response()->json($completion->load('item:id,title,stage_id'));
    }

    protected function authorizeView(Request $request, Customer $customer): void
    {
        $scope = app(ScopeService::class);
        $query = Customer::where('id', $customer->id);
        $scope->applyCustomerScope($query, $request->user());

        abort_unless($query->exists(), 403);
    }
}
