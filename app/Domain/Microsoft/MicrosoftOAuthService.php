<?php

namespace App\Domain\Microsoft;

use App\Models\MicrosoftConnection;
use App\Models\User;
use App\Support\SafeReturnPath;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class MicrosoftOAuthService
{
    public function isConfigured(): bool
    {
        return (bool) (config('microsoft.client_id') && config('microsoft.client_secret') && config('microsoft.tenant_id'));
    }

    public function authorizationUrl(User $user, string $returnPath = '/settings'): string
    {
        MicrosoftOAuthConfigGuard::assertMailRedirectUri();

        $state = Str::random(40);
        Cache::put('microsoft.oauth.'.$state, [
            'user_id' => $user->id,
            'return' => $returnPath,
        ], now()->addMinutes(10));

        $query = http_build_query([
            'client_id' => config('microsoft.client_id'),
            'response_type' => 'code',
            'redirect_uri' => config('microsoft.redirect_uri'),
            'response_mode' => 'query',
            'scope' => implode(' ', config('microsoft.scopes')),
            'state' => $state,
        ]);

        return $this->authorizeEndpoint().'?'.$query;
    }

    /**
     * @return array{connection: MicrosoftConnection, return_path: string}
     */
    public function handleCallback(string $code, string $state): array
    {
        $payload = Cache::pull('microsoft.oauth.'.$state);
        if (!$payload) {
            throw new RuntimeException('Invalid or expired OAuth state.');
        }

        $returnPath = '/settings';
        $userId = is_array($payload) ? ($payload['user_id'] ?? null) : $payload;
        if (is_array($payload)) {
            $returnPath = SafeReturnPath::normalize($payload['return'] ?? '/settings', '/settings');
        }
        if (!$userId) {
            throw new RuntimeException('Invalid or expired OAuth state.');
        }

        $user = User::findOrFail($userId);
        $tokens = $this->exchangeCode($code);

        $profile = Http::withToken($tokens['access_token'])
            ->get(config('microsoft.graph_base').'/me')
            ->throw()
            ->json();

        $connection = MicrosoftConnection::updateOrCreate(
            ['user_id' => $user->id],
            [
                'mailbox_upn' => $profile['userPrincipalName'] ?? $profile['mail'] ?? null,
                'access_token' => $tokens['access_token'],
                'refresh_token' => $tokens['refresh_token'],
                'expires_at' => now()->addSeconds((int) ($tokens['expires_in'] ?? 3600)),
                'scopes' => explode(' ', $tokens['scope'] ?? implode(' ', config('microsoft.scopes'))),
            ]
        );

        return ['connection' => $connection, 'return_path' => $returnPath];
    }

    public function disconnect(User $user): void
    {
        MicrosoftConnection::where('user_id', $user->id)->delete();
    }

    /** @return array{access_token: string, refresh_token?: string, expires_in: int, scope?: string} */
    protected function exchangeCode(string $code): array
    {
        $response = Http::asForm()->post($this->tokenEndpoint(), [
            'client_id' => config('microsoft.client_id'),
            'client_secret' => config('microsoft.client_secret'),
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => config('microsoft.redirect_uri'),
            'scope' => implode(' ', config('microsoft.scopes')),
        ]);

        if (!$response->ok()) {
            throw new RuntimeException('Microsoft token exchange failed: '.$response->body());
        }

        return $response->json();
    }

    protected function authorizeEndpoint(): string
    {
        return config('microsoft.authority').'/'.config('microsoft.tenant_id').'/oauth2/v2.0/authorize';
    }

    protected function tokenEndpoint(): string
    {
        return config('microsoft.authority').'/'.config('microsoft.tenant_id').'/oauth2/v2.0/token';
    }
}
