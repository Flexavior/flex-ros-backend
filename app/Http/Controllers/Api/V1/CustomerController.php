<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Domain\Crm\ChecklistService;
use App\Domain\Crm\ScopeService;
use App\Models\Customer;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    public function __construct(
        protected ScopeService $scopeService,
        protected ChecklistService $checklistService
    ) {
    }

    public function index(Request $request)
    {
        $query = Customer::with(['owner:id,name', 'products:id,name,code'])
            ->orderByDesc('created_at');

        $query = $this->scopeService->applyCustomerScope($query, $request->user());

        return response()->json($query->paginate($request->integer('per_page', 25)));
    }

    public function show(Request $request, Customer $customer)
    {
        $this->authorizeView($request, $customer);

        return response()->json([
            'customer' => $customer->load(['owner:id,name', 'products', 'agreements', 'launchPlans.dependencies']),
            'checklists' => $this->checklistService->completionFor($customer),
        ]);
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
