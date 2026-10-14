<?php

namespace Tests\Feature\Api;

use App\Models\Setting;
use App\Models\User;
use App\Support\SsoSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class SettingsGeneralSsoApiTest extends TestCase
{
    use RefreshDatabase;

    protected function seedRoles(): void
    {
        $this->seed(\Database\Seeders\RoleAndUserSeeder::class);
    }

    public function test_admin_can_toggle_sso_and_login_config_reflects_without_manual_cache_clear(): void
    {
        $this->seedRoles();
        $admin = User::where('email', 'admin@mss.test')->first();

        $this->actingAs($admin)
            ->putJson('/api/v1/settings/general', [
                SsoSetting::settingKey('microsoft') => true,
                SsoSetting::settingKey('google') => false,
            ])
            ->assertOk()
            ->assertJson([
                SsoSetting::settingKey('microsoft') => true,
                SsoSetting::settingKey('google') => false,
            ]);

        $this->getJson('/api/v1/auth/sso/config')
            ->assertOk()
            ->assertJsonPath('microsoft', true)
            ->assertJsonPath('google', false);

        Cache::put('settings.all', ['stale' => true], 3600);

        $this->getJson('/api/v1/auth/sso/config')
            ->assertOk()
            ->assertJsonPath('microsoft', true);

        $this->actingAs($admin)
            ->putJson('/api/v1/settings/general', [
                SsoSetting::settingKey('microsoft') => false,
            ])
            ->assertOk();

        $this->getJson('/api/v1/auth/sso/config')
            ->assertOk()
            ->assertJsonPath('microsoft', false);

        $this->assertFalse(SsoSetting::isProviderEnabled('microsoft'));
        $this->assertSame(false, Setting::get(SsoSetting::settingKey('microsoft')));
    }
}
