<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Microsoft\GraphSubscriptionService;
use App\Domain\Microsoft\MicrosoftOAuthService;
use App\Http\Controllers\Controller;
use App\Models\MicrosoftConnection;
use Illuminate\Http\Request;
use RuntimeException;

class MicrosoftIntegrationController extends Controller
{
    public function status(Request $request, MicrosoftOAuthService $oauth)
    {
        $connection = MicrosoftConnection::where('user_id', $request->user()->id)->first();

        return response()->json([
            'configured' => $oauth->isConfigured(),
            'connected' => (bool) $connection,
            'mailbox_upn' => $connection?->mailbox_upn,
            'expires_at' => $connection?->expires_at,
        ]);
    }

    public function connect(Request $request, MicrosoftOAuthService $oauth)
    {
        if (!$oauth->isConfigured()) {
            return response()->json(['message' => 'Microsoft integration is not configured on the server.'], 503);
        }

        return response()->json(['url' => $oauth->authorizationUrl($request->user())]);
    }

    public function callback(Request $request, MicrosoftOAuthService $oauth, GraphSubscriptionService $subscriptions)
    {
        $request->validate([
            'code' => 'required|string',
            'state' => 'required|string',
        ]);

        $frontend = rtrim(env('FRONTEND_URL', 'http://localhost:5173'), '/');

        try {
            $connection = $oauth->handleCallback($request->query('code'), $request->query('state'));
        } catch (RuntimeException $e) {
            return redirect($frontend.'/settings?microsoft=error&msg='.urlencode($e->getMessage()));
        }

        $subscriptions->ensureInboxSubscription($connection->user);

        return redirect($frontend.'/settings?microsoft=connected');
    }

    public function disconnect(Request $request, MicrosoftOAuthService $oauth)
    {
        $oauth->disconnect($request->user());

        return response()->json(['ok' => true]);
    }
}
