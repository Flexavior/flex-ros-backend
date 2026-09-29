<?php

namespace App\Domain\Inbox;

use App\Models\Setting;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * HTTP client for the ConvyMes multi-channel microservice
 * (Node/Express — Facebook Page Messenger, Viber, LINE in one inbox).
 *
 * Endpoint contract used (see docs/05-Convymes-Integration.md):
 *   POST /api/auth/login                          -> { token, user }
 *   GET  /api/conversations                       -> { conversations: [...] }
 *   GET  /api/conversations/:id/messages          -> { conversation, messages }
 *   POST /api/conversations/:id/assign {agentId}  -> { conversation }
 *   POST /api/conversations/:id/reply  {text}     -> { ok, message }
 *   GET  /api/channels                            -> { channels: [{name, configured}] }
 *   GET  /health                                  -> { ok, version }
 */
class ConvymesClient
{
    public function __construct()
    {
        // no dependencies — configuration is read from settings/env on demand
    }

    public function enabled(): bool
    {
        return (bool) $this->config('enabled', false);
    }

    public function baseUrl(): string
    {
        return rtrim((string) $this->config('base_url', ''), '/');
    }

    /** Health probe used by the integration status screen. */
    public function health(): array
    {
        try {
            $res = Http::timeout(5)->get($this->baseUrl() . '/health');

            return ['ok' => $res->ok(), 'status' => $res->status(), 'body' => $res->json()];
        } catch (\Throwable $e) {
            return ['ok' => false, 'status' => 0, 'error' => $e->getMessage()];
        }
    }

    /** @return array<int, array<string,mixed>> */
    public function conversations(): array
    {
        $data = $this->request()->get('/api/conversations')->throw()->json();

        return $data['conversations'] ?? [];
    }

    /** @return array{conversation: array|null, messages: array<int,array<string,mixed>>} */
    public function messages(int|string $externalId): array
    {
        $data = $this->request()->get("/api/conversations/{$externalId}/messages")->throw()->json();

        return [
            'conversation' => $data['conversation'] ?? null,
            'messages' => $data['messages'] ?? [],
        ];
    }

    /** Assign (or unassign with null) a conversation inside the gateway. */
    public function assign(int|string $externalId, ?int $agentId): array
    {
        return $this->request()
            ->post("/api/conversations/{$externalId}/assign", ['agentId' => $agentId])
            ->throw()
            ->json();
    }

    /** Send a reply out through the correct channel adapter. */
    public function reply(int|string $externalId, string $text): array
    {
        return $this->request()
            ->post("/api/conversations/{$externalId}/reply", ['text' => $text])
            ->throw()
            ->json();
    }

    /** @return array<int, array{name: string, configured: bool}> */
    public function channels(): array
    {
        return $this->request()->get('/api/channels')->throw()->json('channels', []);
    }

    /**
     * Authenticated request using a service-account JWT (cached until near expiry).
     */
    protected function request(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl())
            ->timeout(15)
            ->acceptJson()
            ->withToken($this->token());
    }

    protected function token(): string
    {
        $cacheKey = 'convymes.token.' . md5($this->baseUrl() . '|' . $this->config('email', ''));

        return Cache::remember($cacheKey, now()->addMinutes(50), function () {
            $res = Http::baseUrl($this->baseUrl())
                ->timeout(15)
                ->acceptJson()
                ->post('/api/auth/login', [
                    'email' => $this->config('email'),
                    'password' => $this->config('password'),
                ]);

            if (!$res->ok()) {
                throw new RuntimeException('ConvyMes login failed: HTTP ' . $res->status());
            }

            $token = $res->json('token');
            if (!$token) {
                throw new RuntimeException('ConvyMes login response contained no token.');
            }

            return $token;
        });
    }

    /** Drop the cached service token (e.g. after credential changes). */
    public function forgetToken(): void
    {
        Cache::forget('convymes.token.' . md5($this->baseUrl() . '|' . $this->config('email', '')));
    }

    /**
     * Settings-first configuration with .env fallback, so admins can point the CRM at
     * the microservice without a redeploy.
     */
    protected function config(string $key, mixed $default = null): mixed
    {
        $value = Setting::get("integrations.convymes.{$key}");

        if ($value === null || $value === '') {
            $value = match ($key) {
                'base_url' => config('services.convymes.base_url'),
                'email' => config('services.convymes.email'),
                'password' => config('services.convymes.password'),
                'webhook_secret' => config('services.convymes.webhook_secret'),
                'enabled' => config('services.convymes.enabled'),
                default => null,
            };
        }

        return $value ?? $default;
    }
}
