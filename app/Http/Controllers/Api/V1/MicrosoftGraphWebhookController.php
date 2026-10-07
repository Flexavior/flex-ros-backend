<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Microsoft\OutlookIngestService;
use App\Http\Controllers\Controller;
use App\Models\MicrosoftGraphSubscription;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class MicrosoftGraphWebhookController extends Controller
{
    /** Graph validation handshake (GET or POST with validationToken). */
    public function __invoke(Request $request, OutlookIngestService $ingest): Response
    {
        if ($token = $request->query('validationToken')) {
            return response($token, 200)->header('Content-Type', 'text/plain');
        }

        $payload = $request->json()->all();
        foreach ($payload['value'] ?? [] as $notification) {
            if (($notification['clientState'] ?? '') !== config('microsoft.webhook_client_state')) {
                continue;
            }

            $resourceData = $notification['resourceData'] ?? [];
            $messageId = $resourceData['id'] ?? null;
            if (!$messageId) {
                continue;
            }

            $subscriptionId = $notification['subscriptionId'] ?? null;
            $sub = $subscriptionId
                ? MicrosoftGraphSubscription::where('subscription_id', $subscriptionId)->first()
                : null;
            $user = $sub?->user;
            if (!$user) {
                continue;
            }

            try {
                $ingest->ingestRemoteMessage($user, $messageId);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return response()->json(['ok' => true]);
    }
}
