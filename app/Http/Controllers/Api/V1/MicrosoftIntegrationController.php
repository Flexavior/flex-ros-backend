<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Microsoft\GraphSubscriptionService;
use App\Domain\Microsoft\MicrosoftOAuthService;
use App\Http\Controllers\Controller;
use App\Models\MicrosoftConnection;
use App\Support\SafeReturnPath;
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

        $data = $request->validate([
            'return_path' => 'nullable|string|max:200',
        ]);
        $returnPath = SafeReturnPath::normalize($data['return_path'] ?? '/settings', '/settings');

        return response()->json(['url' => $oauth->authorizationUrl($request->user(), $returnPath)]);
    }

    public function callback(Request $request, MicrosoftOAuthService $oauth, GraphSubscriptionService $subscriptions)
    {
        $request->validate([
            'code' => 'required|string',
            'state' => 'required|string',
        ]);

        $frontend = rtrim(env('FRONTEND_URL', 'http://localhost:5173'), '/');
        $returnPath = '/settings';

        try {
            $result = $oauth->handleCallback($request->query('code'), $request->query('state'));
            $connection = $result['connection'];
            $returnPath = $result['return_path'];
        } catch (RuntimeException $e) {
            $base = SafeReturnPath::normalize($returnPath, '/settings');
            $sep = str_contains($base, '?') ? '&' : '?';

            return redirect($frontend.$base.$sep.'microsoft=error&msg='.urlencode($e->getMessage()));
        }

        $subscriptions->ensureInboxSubscription($connection->user);

        $sep = str_contains($returnPath, '?') ? '&' : '?';

        return redirect($frontend.$returnPath.$sep.'microsoft=connected');
    }

    public function disconnect(Request $request, MicrosoftOAuthService $oauth)
    {
        $oauth->disconnect($request->user());

        return response()->json(['ok' => true]);
    }
}
