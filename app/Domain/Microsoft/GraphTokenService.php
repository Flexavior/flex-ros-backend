<?php

namespace App\Domain\Microsoft;

use App\Models\MicrosoftConnection;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class GraphTokenService
{
    public function connectionFor(User $user): ?MicrosoftConnection
    {
        return MicrosoftConnection::where('user_id', $user->id)->first();
    }

    public function accessToken(User $user): string
    {
        $connection = $this->connectionFor($user);
        if (!$connection) {
            throw new RuntimeException('Microsoft 365 is not connected for this user.');
        }

        if (!$connection->isExpired()) {
            return $connection->access_token;
        }

        return $this->refresh($connection);
    }

    public function refresh(MicrosoftConnection $connection): string
    {
        $response = Http::asForm()->post(
            config('microsoft.authority').'/'.config('microsoft.tenant_id').'/oauth2/v2.0/token',
            [
                'client_id' => config('microsoft.client_id'),
                'client_secret' => config('microsoft.client_secret'),
                'grant_type' => 'refresh_token',
                'refresh_token' => $connection->refresh_token,
                'scope' => implode(' ', config('microsoft.scopes')),
            ]
        );

        if (!$response->ok()) {
            throw new RuntimeException('Microsoft token refresh failed. Reconnect Microsoft 365.');
        }

        $data = $response->json();
        $connection->update([
            'access_token' => $data['access_token'],
            'refresh_token' => $data['refresh_token'] ?? $connection->refresh_token,
            'expires_at' => now()->addSeconds((int) ($data['expires_in'] ?? 3600)),
        ]);

        return $connection->access_token;
    }

    public function graph(User $user): \Illuminate\Http\Client\PendingRequest
    {
        return Http::baseUrl(config('microsoft.graph_base'))
            ->acceptJson()
            ->withToken($this->accessToken($user))
            ->timeout(30);
    }
}
