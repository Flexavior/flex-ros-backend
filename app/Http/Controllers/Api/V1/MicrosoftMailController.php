<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Crm\ScopeService;
use App\Domain\Microsoft\GraphMailService;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Lead;
use Illuminate\Http\Request;
use RuntimeException;

class MicrosoftMailController extends Controller
{
    public function sendLead(Request $request, Lead $lead, GraphMailService $mail, ScopeService $scope)
    {
        abort_unless($scope->canAccessLead($request->user(), $lead), 403);

        $data = $request->validate([
            'subject' => 'required|string|max:500',
            'body' => 'required|string|max:50000',
        ]);

        try {
            $result = $mail->sendToLead($request->user(), $lead, $data['subject'], $data['body']);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($result, 201);
    }

    public function sendCustomer(Request $request, Customer $customer, GraphMailService $mail, ScopeService $scope)
    {
        abort_unless($scope->canAccessCustomer($request->user(), $customer), 403);

        $data = $request->validate([
            'subject' => 'required|string|max:500',
            'body' => 'required|string|max:50000',
        ]);

        try {
            $result = $mail->sendToCustomer($request->user(), $customer, $data['subject'], $data['body']);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($result, 201);
    }
}
