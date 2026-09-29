<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\LaunchDependency;
use App\Models\LaunchPlan;
use Illuminate\Http\Request;

class LaunchPlanController extends Controller
{
    public function index(Request $request)
    {
        return response()->json(
            LaunchPlan::with(['customer:id,client_id,name', 'dependencies'])
                ->orderBy('target_date')
                ->get()
        );
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'customer_id' => 'required|exists:customers,id',
            'customer_product_id' => 'nullable|exists:customer_products,id',
            'title' => 'required|string|max:255',
            'plan' => 'nullable|string',
            'target_date' => 'nullable|date',
        ]);

        $plan = LaunchPlan::create($data + [
            'status' => 'planning',
            'created_by' => $request->user()->id,
        ]);

        return response()->json($plan->load('dependencies'), 201);
    }

    public function show(LaunchPlan $launchPlan)
    {
        return response()->json($launchPlan->load(['customer:id,client_id,name', 'dependencies']));
    }

    public function update(Request $request, LaunchPlan $launchPlan)
    {
        $data = $request->validate([
            'title' => 'sometimes|string|max:255',
            'plan' => 'nullable|string',
            'target_date' => 'nullable|date',
            'status' => 'sometimes|in:planning,ready,in_progress,launched,delayed',
        ]);

        if (isset($data['status']) && $data['status'] === 'launched') {
            // Launching requires all blocking dependencies done
            $pendingBlocking = $launchPlan->dependencies()
                ->where('is_blocking', true)
                ->whereNotIn('status', ['done'])
                ->count();
            abort_if($pendingBlocking > 0, 422, 'Blocking dependencies must be completed first.');
            $data['launched_at'] = now();
        }

        $launchPlan->update($data);

        return response()->json($launchPlan->fresh('dependencies'));
    }

    // ---- Dependencies (e.g. "Mobile app released") ----
    public function storeDependency(Request $request, LaunchPlan $launchPlan)
    {
        $data = $request->validate([
            'title' => 'required|string|max:255',
            'type' => 'nullable|in:internal,external',
            'due_date' => 'nullable|date',
            'is_blocking' => 'nullable|boolean',
            'notes' => 'nullable|string',
        ]);

        $dep = $launchPlan->dependencies()->create($data);

        // A plan with pending dependencies cannot be "ready"
        if ($launchPlan->status === 'ready') {
            $launchPlan->update(['status' => 'planning']);
        }

        return response()->json($dep, 201);
    }

    public function updateDependency(Request $request, LaunchDependency $dependency)
    {
        $data = $request->validate([
            'title' => 'sometimes|string|max:255',
            'status' => 'sometimes|in:pending,in_progress,done,blocked',
            'due_date' => 'nullable|date',
            'notes' => 'nullable|string',
        ]);

        $dependency->update($data);

        // When all blocking deps are done, plan becomes "ready"
        $plan = $dependency->launchPlan;
        $pending = $plan->dependencies()->where('is_blocking', true)->whereNotIn('status', ['done'])->count();
        if ($pending === 0 && $plan->status === 'planning') {
            $plan->update(['status' => 'ready']);
        }

        return response()->json($dependency->fresh());
    }
}
