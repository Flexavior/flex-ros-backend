<?php

namespace Tests\Unit\Domain\Auth;

use App\Domain\Auth\SsoService;
use App\Models\Setting;
use App\Support\SsoSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SsoAllowedDomainsTest extends TestCase
{
    use RefreshDatabase;

    public function test_guard_allowed_domain_accepts_json_array_from_settings(): void
    {
        $this->seed(\Database\Seeders\RoleAndUserSeeder::class);

        Setting::put('auth.sso.allowed_email_domains', ['mss.test'], 'auth');
        Cache::forget('settings.all');

        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['access_token' => 't'], 200),
            'graph.microsoft.com/*' => Http::response([
                'id' => '1',
                'mail' => 'user@mss.test',
                'displayName' => 'User',
            ], 200),
        ]);

        Setting::put(SsoSetting::settingKey('microsoft'), true, 'auth');
        config([
            'services.sso.microsoft.tenant_id' => 't',
            'services.sso.microsoft.client_id' => 'c',
            'services.sso.microsoft.client_secret' => 's',
            'services.sso.microsoft.redirect_uri' => 'http://localhost/cb',
        ]);

        $state = 'fixed-state-for-unit';
        Cache::put('auth.sso.state.'.$state, 'microsoft', now()->addMinutes(10));

        $service = app(SsoService::class);
        $exchange = $service->handleCallback('microsoft', 'code', $state);
        $this->assertNotEmpty($exchange);
    }
}
