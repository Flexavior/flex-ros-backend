<?php

namespace Tests\Feature\Api;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingsAndDashboardApiTest extends TestCase
{
    use RefreshDatabase;

    protected function seedRoles(): void
    {
        $this->seed(\Database\Seeders\RoleAndUserSeeder::class);
    }

    // FT-08: admin updates dynamic checklists
    public function test_admin_can_update_checklist_settings(): void
    {
        $this->seedRoles();
        $admin = User::where('email', 'admin@mss.test')->first();

        $res = $this->actingAs($admin)->putJson('/api/v1/settings/checklists', [
            'contract_sign_off' => [
                ['title' => 'Contract signed', 'is_required' => true],
                ['title' => 'Bank account opened', 'is_required' => true],
            ],
        ]);

        $res->assertOk()->assertJsonPath('updated.0', 'contract_sign_off');

        $this->assertDatabaseHas('settings', ['key' => 'crm.checklists.contract_sign_off']);
    }

    // FT-09: non-admin blocked from settings write
    public function test_supervisor_cannot_update_settings(): void
    {
        $this->seedRoles();
        $supervisor = User::where('email', 'supervisor@mss.test')->first();

        $this->actingAs($supervisor)
            ->putJson('/api/v1/settings/checklists', [
                'contract_sign_off' => [['title' => 'Hacked item']],
            ])
            ->assertStatus(403);
    }

    // FT-12: dashboard metrics shape for CEO
    public function test_dashboard_metrics_for_ceo(): void
    {
        $this->seedRoles();
        $ceo = User::where('email', 'ceo@mss.test')->first();

        \App\Models\Lead::factory()->count(4)->create();          // open
        \App\Models\Lead::factory()->stale(6)->count(2)->create(); // stale
        \App\Models\Lead::factory()->converted()->count(2)->create();

        $res = $this->actingAs($ceo)->getJson('/api/v1/dashboard/metrics');

        $res->assertOk()
            ->assertJsonStructure([
                'conversion_rate',
                'leads' => ['total', 'open', 'converted'],
                'stale_tasks' => ['threshold_days', 'count', 'items'],
                'funnel',
                'appointments_this_week',
                'checklist',
            ]);

        $data = $res->json();
        $this->assertSame(8, $data['leads']['total']);
        $this->assertSame(2, $data['leads']['converted']);
        $this->assertEqualsWithDelta(25.0, $data['conversion_rate'], 0.01); // UT-07 expectation via API
        $this->assertSame(5, $data['stale_tasks']['threshold_days']);
        $this->assertSame(2, $data['stale_tasks']['count']);
    }

    // FT-11: marketing approval flow
    public function test_campaign_approval_flow(): void
    {
        $this->seedRoles();
        $marketer = User::where('email', 'marketing@mss.test')->first();
        $supervisor = User::where('email', 'supervisor@mss.test')->first();

        $channel = \App\Models\MarketingChannel::firstOrCreate(
            ['code' => 'telegram'],
            ['name' => 'Telegram', 'type' => 'messaging']
        );

        // Marketing creates campaign
        $create = $this->actingAs($marketer)->postJson('/api/v1/marketing/campaigns', [
            'channel_id' => $channel->id,
            'name' => 'Q4 Telegram push',
        ]);
        $create->assertCreated()->assertJsonPath('approval_status', 'draft');
        $campaignId = $create->json('id');

        // Submit for approval
        $this->actingAs($marketer)->postJson("/api/v1/marketing/campaigns/{$campaignId}/submit")
            ->assertOk()->assertJsonPath('approval_status', 'pending');

        // Staff cannot approve (403)
        $staff = User::where('email', 'staff@mss.test')->first();
        $this->actingAs($staff)
            ->postJson("/api/v1/marketing/campaigns/{$campaignId}/decide", ['decision' => 'approved'])
            ->assertStatus(403);

        // Supervisor approves
        $this->actingAs($supervisor)
            ->postJson("/api/v1/marketing/campaigns/{$campaignId}/decide", ['decision' => 'approved'])
            ->assertOk()->assertJsonPath('approval_status', 'approved');
    }

    // FT-14: launch dependency flow
    public function test_launch_dependency_blocks_launch_until_done(): void
    {
        $this->seedRoles();
        $sales = User::where('email', 'sales@mss.test')->first();

        $customer = \App\Models\Customer::create([
            'client_id' => 'CUS-2026-5001',
            'name' => 'Launch Client',
            'owner_id' => $sales->id,
        ]);

        $plan = $this->actingAs($sales)->postJson('/api/v1/launch-plans', [
            'customer_id' => $customer->id,
            'title' => 'Go-live plan',
            'target_date' => now()->addMonth()->toDateString(),
        ])->assertCreated()->json('id');

        // Add external dependency: mobile app release
        $dep = $this->actingAs($sales)
            ->postJson("/api/v1/launch-plans/{$plan}/dependencies", [
                'title' => 'Mobile app released',
                'type' => 'external',
                'is_blocking' => true,
            ])->assertCreated()->json('id');

        // Launch blocked while dependency pending
        $this->actingAs($sales)
            ->putJson("/api/v1/launch-plans/{$plan}", ['status' => 'launched'])
            ->assertStatus(422);

        // Complete dependency -> plan becomes ready
        $this->actingAs($sales)
            ->putJson("/api/v1/launch-dependencies/{$dep}", ['status' => 'done'])
            ->assertOk();

        $this->assertSame('ready', \App\Models\LaunchPlan::find($plan)->fresh()->status);

        // Now launch succeeds
        $this->actingAs($sales)
            ->putJson("/api/v1/launch-plans/{$plan}", ['status' => 'launched'])
            ->assertOk();
    }
}
