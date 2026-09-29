<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Agreement;
use App\Models\Role;
use Illuminate\Http\Request;

class AgreementController extends Controller
{
    public function index(Request $request)
    {
        $query = Agreement::with(['customer:id,client_id,name', 'createdBy:id,name'])
            ->orderByDesc('created_at');

        if ($type = $request->query('type')) {
            $query->where('type', $type);
        }
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        return response()->json($query->get());
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'customer_id' => 'required|exists:customers,id',
            'type' => 'required|in:nda,mou,contract',
            'title' => 'required|string|max:255',
            'document_url' => 'nullable|string',
            'signatory_name' => 'nullable|string|max:255',
            'signatory_title' => 'nullable|string|max:255',
            'value' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
        ]);

        $agreement = Agreement::create($data + [
            'status' => 'draft',
            'created_by' => $request->user()->id,
        ]);

        return response()->json($agreement, 201);
    }

    public function update(Request $request, Agreement $agreement)
    {
        $data = $request->validate([
            'title' => 'sometimes|string|max:255',
            'document_url' => 'nullable|string',
            'status' => 'sometimes|in:draft,sent,in_review,pending_signature,signed,rejected,expired',
            'signatory_name' => 'nullable|string|max:255',
            'signatory_title' => 'nullable|string|max:255',
            'value' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
        ]);

        $status = $data['status'] ?? null;
        if ($status === 'signed') {
            // Sign-off requires CEO / Senior Management / Supervisor
            abort_unless($request->user()->canApprove(), 403, 'Sign-off requires an approver role.');
            $data['signed_at'] = now();
        }
        if ($status === 'sent' && $agreement->status === 'draft') {
            $data['sent_at'] = now();
        }

        $agreement->update($data);

        return response()->json($agreement->fresh());
    }

    public function destroy(Agreement $agreement)
    {
        $agreement->delete();

        return response()->json(['message' => 'Agreement deleted.']);
    }
}
