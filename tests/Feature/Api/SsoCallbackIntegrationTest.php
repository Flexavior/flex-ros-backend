<?php

namespace Tests\Feature\Api;

use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Support\SsoSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * SSO redirect → callback → exchange without calling real Microsoft/Google (Http::fake).
 * PHPUnit uses CACHE_STORE=array: state must be consumed in the same test process (not manual localhost browser hops).
 */
class SsoCallbackIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private const FRONTEND = 'http://localhost:5173';

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.frontend_url' => self::FRONTEND]);
        putenv('FRONTEND_URL='.self::FRONTEND);
    }

    protected function seedRoles(): void
    {
        $this->seed(\Database\Seeders\RoleAndUserSeeder::class);
    }

    public function test_microsoft_sso_flow_with_faked_token_and_profile(): void
    {
        $this->seedRoles();
        $this->enableMicrosoftSso();

        Http::fake([
            'login.microsoftonline.com/*' => Http::response([
                'access_token' => 'ms-access-token',
                'token_type' => 'Bearer',
            ], 200),
            'graph.microsoft.com/*' => Http::response([
                'id' => 'ms-user-oid-1',
                'mail' => 'newsso@mss.test',
                'displayName' => 'New SSO User',
            ], 200),
        ]);

        $redirectRes = $this->getJson('/api/v1/auth/sso/microsoft/redirect')->assertOk();
        $authUrl = (string) $redirectRes->json('url');
        $this->assertStringContainsString('login.microsoftonline.com', $authUrl);
        parse_str((string) parse_url($authUrl, PHP_URL_QUERY), $query);
        $this->assertNotEmpty($query['state'] ?? null);

        $callback = $this->get('/api/v1/auth/sso/microsoft/callback?'.http_build_query([
            'code' => 'fake-auth-code',
            'state' => $query['state'],
        ]));
        $callback->assertRedirect();
        $location = $callback->headers->get('Location');
        $this->assertStringStartsWith(self::FRONTEND.'/auth/sso/callback?code=', $location);
        $this->assertStringNotContainsString('error=', $location);

        parse_str((string) parse_url($location, PHP_URL_QUERY), $cbQuery);
        $exchangeRes = $this->postJson('/api/v1/auth/sso/exchange', [
            'code' => $cbQuery['code'],
        ])->assertOk();

        $exchangeRes->assertJsonStructure(['token', 'user' => ['email', 'role']]);
        $this->assertSame('newsso@mss.test', $exchangeRes->json('user.email'));
        $this->assertSame(Role::STAFF, $exchangeRes->json('user.role.code'));
    }

    public function test_google_sso_flow_with_faked_token_and_profile(): void
    {
        $this->seedRoles();
        $this->enableGoogleSso();

        Http::fake([
            'oauth2.googleapis.com/*' => Http::response([
                'access_token' => 'google-access-token',
                'token_type' => 'Bearer',
            ], 200),
            'openidconnect.googleapis.com/*' => Http::response([
                'sub' => 'google-sub-99',
                'email' => 'googleuser@mss.test',
                'name' => 'Google User',
            ], 200),
        ]);

        $redirectRes = $this->getJson('/api/v1/auth/sso/google/redirect')->assertOk();
        parse_str((string) parse_url((string) $redirectRes->json('url'), PHP_URL_QUERY), $query);

        $callback = $this->get('/api/v1/auth/sso/google/callback?'.http_build_query([
            'code' => 'fake-google-code',
            'state' => $query['state'],
        ]));
        $callback->assertRedirect();
        $location = (string) $callback->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $cbQuery);

        $this->postJson('/api/v1/auth/sso/exchange', ['code' => $cbQuery['code']])
            ->assertOk()
            ->assertJsonPath('user.email', 'googleuser@mss.test');
    }

    public function test_microsoft_callback_with_invalid_state_redirects_error_to_frontend(): void
    {
        $this->seedRoles();
        $this->enableMicrosoftSso();

        $this->get('/api/v1/auth/sso/microsoft/callback?'.http_build_query([
            'code' => 'fake-auth-code',
            'state' => 'not-in-cache-state',
        ]))
            ->assertRedirect()
            ->assertRedirectContains(urlencode('Invalid or expired SSO state'));
    }

    private function enableMicrosoftSso(): void
    {
        Setting::put(SsoSetting::settingKey('microsoft'), true, 'auth');
        Setting::put('auth.sso.allowed_email_domains', [], 'auth');
        config([
            'services.sso.microsoft.tenant_id' => 'test-tenant',
            'services.sso.microsoft.client_id' => 'test-ms-client',
            'services.sso.microsoft.client_secret' => 'test-ms-secret',
            'services.sso.microsoft.redirect_uri' => 'http://localhost/api/v1/auth/sso/microsoft/callback',
        ]);
    }

    private function enableGoogleSso(): void
    {
        Setting::put(SsoSetting::settingKey('google'), true, 'auth');
        Setting::put('auth.sso.allowed_email_domains', [], 'auth');
        config([
            'services.sso.google.client_id' => 'test-google-client',
            'services.sso.google.client_secret' => 'test-google-secret',
            'services.sso.google.redirect_uri' => 'http://localhost/api/v1/auth/sso/google/callback',
        ]);
    }
}
