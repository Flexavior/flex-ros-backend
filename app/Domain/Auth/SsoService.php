<?php

namespace App\Domain\Auth;

use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Models\UserIdentity;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class SsoService
{
    private const STATE_CACHE_PREFIX = 'auth.sso.state.';
    private const EXCHANGE_CACHE_PREFIX = 'auth.sso.exchange.';

    public function config(): array
    {
        return [
            'microsoft' => $this->isProviderEnabled('microsoft'),
            'google' => $this->isProviderEnabled('google'),
        ];
    }

    public function redirectUrl(string $provider): string
    {
        $provider = $this->normalizeProvider($provider);
        $this->guardEnabled($provider);

        $state = Str::random(48);
        Cache::put(self::STATE_CACHE_PREFIX.$state, $provider, now()->addMinutes(10));

        return match ($provider) {
            'microsoft' => $this->microsoftAuthUrl($state),
            'google' => $this->googleAuthUrl($state),
            default => throw new RuntimeException('Unsupported SSO provider.'),
        };
    }

    public function handleCallback(string $provider, string $code, string $state): string
    {
        $provider = $this->normalizeProvider($provider);
        $stateProvider = Cache::pull(self::STATE_CACHE_PREFIX.$state);
        if (!$stateProvider || $stateProvider !== $provider) {
            throw new RuntimeException('Invalid or expired SSO state.');
        }

        $claims = match ($provider) {
            'microsoft' => $this->microsoftProfile($code),
            'google' => $this->googleProfile($code),
            default => throw new RuntimeException('Unsupported SSO provider.'),
        };

        $user = $this->resolveUser($provider, $claims);
        if (!$user->is_active) {
            throw new RuntimeException('Account is disabled.');
        }

        $exchangeCode = Str::random(64);
        Cache::put(self::EXCHANGE_CACHE_PREFIX.$exchangeCode, $user->id, now()->addMinutes(2));

        return $exchangeCode;
    }

    public function exchange(string $exchangeCode): array
    {
        $userId = Cache::pull(self::EXCHANGE_CACHE_PREFIX.$exchangeCode);
        if (!$userId) {
            throw new RuntimeException('Invalid or expired SSO exchange code.');
        }

        $user = User::with('role', 'supervisor', 'team')->findOrFail($userId);
        $token = $user->createToken('spa')->plainTextToken;

        return [
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role?->only(['code', 'name', 'level']),
                'supervisor' => $user->supervisor?->only(['id', 'name']),
                'team' => $user->team?->only(['id', 'name']),
                'is_active' => $user->is_active,
            ],
        ];
    }

    private function resolveUser(string $provider, array $claims): User
    {
        $providerUserId = (string) ($claims['provider_user_id'] ?? '');
        $email = strtolower((string) ($claims['email'] ?? ''));
        $name = (string) ($claims['name'] ?? '');

        if ($providerUserId === '' || $email === '') {
            throw new RuntimeException('SSO profile did not return the required identity claims.');
        }

        $this->guardAllowedDomain($email);

        $identity = UserIdentity::where('provider', $provider)
            ->where('provider_user_id', $providerUserId)
            ->first();

        if ($identity) {
            return $identity->user()->firstOrFail();
        }

        $user = User::whereRaw('LOWER(email) = ?', [$email])->first();
        if (!$user) {
            $staffRoleId = Role::where('code', Role::STAFF)->value('id');
            if (!$staffRoleId) {
                throw new RuntimeException('Staff role is not configured.');
            }

            $user = User::create([
                'name' => $name !== '' ? $name : Str::before($email, '@'),
                'email' => $email,
                'password' => Str::random(64),
                'role_id' => $staffRoleId,
                'team_id' => null,
                'supervisor_id' => null,
                'is_active' => true,
                'email_verified_at' => now(),
            ]);
        }

        UserIdentity::create([
            'user_id' => $user->id,
            'provider' => $provider,
            'provider_user_id' => $providerUserId,
            'email' => $email,
            'raw_claims' => $claims['raw_claims'] ?? null,
        ]);

        return $user;
    }

    private function guardAllowedDomain(string $email): void
    {
        $setting = Setting::get('auth.sso.allowed_email_domains', config('services.sso.allowed_email_domains', ''));
        $domains = collect(explode(',', (string) $setting))
            ->map(fn ($d) => strtolower(trim($d)))
            ->filter()
            ->values();

        if ($domains->isEmpty()) {
            return;
        }

        $domain = strtolower((string) Str::after($email, '@'));
        if (!$domains->contains($domain)) {
            throw new RuntimeException('Your email domain is not allowed for SSO login.');
        }
    }

    private function isProviderEnabled(string $provider): bool
    {
        $settingKey = "auth.sso.{$provider}_enabled";
        $settingValue = Setting::get($settingKey, null);
        if ($settingValue !== null) {
            return filter_var($settingValue, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? false;
        }

        return (bool) config("services.sso.{$provider}.enabled", false);
    }

    private function guardEnabled(string $provider): void
    {
        if (!$this->isProviderEnabled($provider)) {
            throw new RuntimeException("{$provider} SSO is disabled.");
        }
    }

    private function normalizeProvider(string $provider): string
    {
        $provider = strtolower(trim($provider));
        if (!in_array($provider, ['microsoft', 'google'], true)) {
            throw new RuntimeException('Unsupported SSO provider.');
        }

        return $provider;
    }

    private function microsoftAuthUrl(string $state): string
    {
        $tenant = config('services.sso.microsoft.tenant_id');
        $clientId = config('services.sso.microsoft.client_id');
        $redirectUri = config('services.sso.microsoft.redirect_uri');
        if (!$tenant || !$clientId || !$redirectUri) {
            throw new RuntimeException('Microsoft SSO is not configured on the server.');
        }

        return rtrim(config('services.sso.microsoft.authority', 'https://login.microsoftonline.com'), '/')
            .'/'.$tenant.'/oauth2/v2.0/authorize?'.http_build_query([
                'client_id' => $clientId,
                'response_type' => 'code',
                'redirect_uri' => $redirectUri,
                'response_mode' => 'query',
                'scope' => 'openid profile email',
                'state' => $state,
            ]);
    }

    private function microsoftProfile(string $code): array
    {
        $tenant = config('services.sso.microsoft.tenant_id');
        $tokenUrl = rtrim(config('services.sso.microsoft.authority', 'https://login.microsoftonline.com'), '/')
            .'/'.$tenant.'/oauth2/v2.0/token';

        $response = Http::asForm()->post($tokenUrl, [
            'client_id' => config('services.sso.microsoft.client_id'),
            'client_secret' => config('services.sso.microsoft.client_secret'),
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => config('services.sso.microsoft.redirect_uri'),
            'scope' => 'openid profile email',
        ]);
        if (!$response->ok()) {
            throw new RuntimeException('Microsoft SSO token exchange failed.');
        }

        $accessToken = (string) $response->json('access_token');
        $profile = Http::withToken($accessToken)->get('https://graph.microsoft.com/v1.0/me');
        if (!$profile->ok()) {
            throw new RuntimeException('Microsoft SSO profile request failed.');
        }

        $p = $profile->json();
        return [
            'provider_user_id' => (string) ($p['id'] ?? ''),
            'email' => (string) ($p['mail'] ?? $p['userPrincipalName'] ?? ''),
            'name' => (string) ($p['displayName'] ?? ''),
            'raw_claims' => $p,
        ];
    }

    private function googleAuthUrl(string $state): string
    {
        $clientId = config('services.sso.google.client_id');
        $redirectUri = config('services.sso.google.redirect_uri');
        if (!$clientId || !$redirectUri) {
            throw new RuntimeException('Google SSO is not configured on the server.');
        }

        return 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => 'openid profile email',
            'state' => $state,
            'access_type' => 'offline',
            'prompt' => 'consent',
        ]);
    }

    private function googleProfile(string $code): array
    {
        $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'client_id' => config('services.sso.google.client_id'),
            'client_secret' => config('services.sso.google.client_secret'),
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => config('services.sso.google.redirect_uri'),
        ]);
        if (!$response->ok()) {
            throw new RuntimeException('Google SSO token exchange failed.');
        }

        $accessToken = (string) $response->json('access_token');
        $profile = Http::withToken($accessToken)->get('https://openidconnect.googleapis.com/v1/userinfo');
        if (!$profile->ok()) {
            throw new RuntimeException('Google SSO profile request failed.');
        }

        $p = $profile->json();
        return [
            'provider_user_id' => (string) ($p['sub'] ?? ''),
            'email' => (string) ($p['email'] ?? ''),
            'name' => (string) ($p['name'] ?? ''),
            'raw_claims' => $p,
        ];
    }
}
