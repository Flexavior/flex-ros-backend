<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingsLeadPicklistsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_admin_cannot_update_picklists(): void
    {
        $this->seed(\Database\Seeders\RoleAndUserSeeder::class);
        $sales = User::where('email', 'sales@mss.test')->first();

        $this->actingAs($sales)
            ->putJson('/api/v1/settings/lead-picklists', ['industry' => ['A']])
            ->assertStatus(403);
    }

    public function test_admin_can_update_industry_list(): void
    {
        $this->seed(\Database\Seeders\RoleAndUserSeeder::class);
        $admin = User::where('email', 'admin@mss.test')->first();

        $this->actingAs($admin)
            ->putJson('/api/v1/settings/lead-picklists', [
                'industry' => ['Alpha', 'Beta'],
            ])
            ->assertOk()
            ->assertJsonPath('industry.0', 'Alpha');
    }

    public function test_admin_update_rejects_unknown_key(): void
    {
        $this->seed(\Database\Seeders\RoleAndUserSeeder::class);
        $admin = User::where('email', 'admin@mss.test')->first();

        $this->actingAs($admin)
            ->putJson('/api/v1/settings/lead-picklists', [
                'not_a_catalog' => ['x'],
            ])
            ->assertStatus(422);
    }

    public function test_admin_can_update_qualify_settings_with_picklist_outcomes(): void
    {
        $this->seed(\Database\Seeders\RoleAndUserSeeder::class);
        $admin = User::where('email', 'admin@mss.test')->first();

        $res = $this->actingAs($admin)
            ->putJson('/api/v1/settings/general', [
                'crm.qualify' => [
                    'max_idle_touches' => 6,
                    'progress_outcomes' => ['Demo booked', 'Proposal sent'],
                ],
            ])->assertOk();

        $payload = $res->json();
        $qualify = $payload['crm.qualify'] ?? null;
        $this->assertIsArray($qualify);
        $this->assertSame(6, $qualify['max_idle_touches'] ?? null);
        $this->assertSame('Demo booked', $qualify['progress_outcomes'][0] ?? null);
    }
}
