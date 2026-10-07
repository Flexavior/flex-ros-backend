<?php

namespace App\Domain\Microsoft;

use App\Models\MicrosoftConnection;
use App\Models\MicrosoftGraphSubscription;
use App\Models\User;
use Illuminate\Support\Facades\Http;

class GraphSubscriptionService
{
    public function __construct(protected GraphTokenService $tokens)
    {
    }

    public function ensureInboxSubscription(User $user): ?MicrosoftGraphSubscription
    {
        if (!$this->tokens->connectionFor($user)) {
            return null;
        }

        $existing = MicrosoftGraphSubscription::where('user_id', $user->id)
            ->where('expires_at', '>', now()->addHours(1))
            ->first();

        if ($existing) {
            return $existing;
        }

        $notificationUrl = url('/api/v1/webhooks/microsoft/graph');
        $resource = "/me/mailFolders('Inbox')/messages";

        $response = $this->tokens->graph($user)->post('/subscriptions', [
            'changeType' => 'created',
            'notificationUrl' => $notificationUrl,
            'resource' => $resource,
            'expirationDateTime' => now()->addDays(2)->toIso8601String(),
            'clientState' => config('microsoft.webhook_client_state'),
        ]);

        if (!$response->ok()) {
            report(new \RuntimeException('Graph subscription failed: '.$response->body()));

            return null;
        }

        $data = $response->json();

        return MicrosoftGraphSubscription::updateOrCreate(
            ['user_id' => $user->id],
            [
                'subscription_id' => $data['id'],
                'resource' => $data['resource'] ?? $resource,
                'expires_at' => $data['expirationDateTime'],
            ]
        );
    }

    public function renewAll(): int
    {
        $count = 0;
        foreach (MicrosoftGraphSubscription::where('expires_at', '<', now()->addDay())->get() as $sub) {
            $user = $sub->user;
            if (!$user) {
                continue;
            }

            $response = $this->tokens->graph($user)->patch('/subscriptions/'.$sub->subscription_id, [
                'expirationDateTime' => now()->addDays(2)->toIso8601String(),
            ]);

            if ($response->ok()) {
                $sub->update(['expires_at' => $response->json('expirationDateTime')]);
                $count++;
            }
        }

        return $count;
    }
}
