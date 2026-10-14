<?php

namespace Tests\Feature\Api;

use App\Models\Setting;
use App\Models\User;
use App\Support\SsoSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminConsoleOverviewRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function seedRoles(): void
    {
        $this->seed(\Database\Seeders\RoleAndUserSeeder::class);
    }

    public function test_admin_overview_returns_sso_flags_and_stale_days_from_settings(): void
    {
        $this->seedRoles();
        $admin = User::where('email', 'admin@mss.test')->first();

        Setting::put('crm.stale_task_days', 9, 'crm', $admin->id);
        Setting::put(SsoSetting::settingKey('microsoft'), true, 'auth', $admin->id);
        Setting::put(SsoSetting::settingKey('google'), false, 'auth', $admin->id);

        $this->actingAs($admin)
            ->getJson('/api/v1/admin/overview')
            ->assertOk()
            ->assertJsonPath('crm.stale_task_days', 9)
            ->assertJsonPath('auth.sso_microsoft_enabled', true)
            ->assertJsonPath('auth.sso_google_enabled', false)
            ->assertJsonStructure([
                'users' => ['total', 'active', 'awaiting_role_assignment'],
                'realtime' => ['broadcast_driver', 'reverb_configured', 'inbox_live_updates'],
                'crm' => ['stale_task_days', 'client_id_format'],
            ]);
    }
}
