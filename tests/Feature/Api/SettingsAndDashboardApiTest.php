<?php

namespace Tests\Feature\Api;

use App\Models\Agreement;
use App\Models\Customer;
use App\Models\Engagement;
use App\Models\Setting;
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

    public function test_dashboard_funnel_groups_by_current_stage(): void
    {
        $this->seedRoles();
        $ceo = User::where('email', 'ceo@mss.test')->first();

        \App\Models\Lead::factory()->create([
            'current_stage' => 'Qualified',
            'status' => 'qualified',
        ]);
        \App\Models\Lead::factory()->create([
            'current_stage' => 'Qualified',
            'status' => 'new',
        ]);
        \App\Models\Lead::factory()->create([
            'current_stage' => 'Negotiation',
            'status' => 'qualified',
        ]);

        $funnel = $this->actingAs($ceo)->getJson('/api/v1/dashboard/metrics')->json('funnel');

        $this->assertSame(2, $funnel['Qualified'] ?? 0);
        $this->assertSame(1, $funnel['Negotiation'] ?? 0);
        $this->assertArrayNotHasKey('new', $funnel);
    }

    public function test_dashboard_metrics_keeps_backward_compatible_shape_with_new_sections(): void
    {
        $this->seedRoles();
        $ceo = User::where('email', 'ceo@mss.test')->first();

        $res = $this->actingAs($ceo)->getJson('/api/v1/dashboard/metrics');

        $res->assertOk()->assertJsonStructure([
            // legacy
            'conversion_rate',
            'leads' => ['total', 'open', 'converted'],
            'stale_tasks' => ['threshold_days', 'count', 'items'],
            'funnel',
            'appointments_this_week',
            'checklist',
            // dynamic dashboard additions
            'persona',
            'sections',
            'period' => ['label', 'start', 'end'],
            'comparisons' => [
                'qoq' => ['previous_period'],
                'yoy' => ['previous_period'],
            ],
            'action_items',
            'rep_activity_this_week',
            'mql_sql' => ['mql', 'sql', 'ratio_pct', 'period', 'mql_stages', 'sql_stages'],
            'revenue' => [
                'signed' => ['amount', 'count', 'period'],
                'pipeline' => ['lead_count', 'stages'],
            ],
            'generated_at',
        ]);
    }

    public function test_admin_dashboard_stage_settings_validation_and_persistence(): void
    {
        $this->seedRoles();
        $admin = User::where('email', 'admin@mss.test')->first();

        $this->actingAs($admin)->putJson('/api/v1/settings/general', [
            'crm.dashboard.mql_stages' => ['Not A Real Stage'],
        ])->assertStatus(422);

        $payload = [
            'crm.dashboard.mql_stages' => ['New', 'Contacted'],
            'crm.dashboard.sql_stages' => ['Qualified', 'Proposal Sent'],
            'crm.dashboard.pipeline_stages' => ['Proposal Sent'],
        ];

        $this->actingAs($admin)->putJson('/api/v1/settings/general', $payload)
            ->assertOk()
            ->assertJson($payload);

        $this->assertSame($payload['crm.dashboard.mql_stages'], Setting::get('crm.dashboard.mql_stages'));
        $this->assertSame($payload['crm.dashboard.sql_stages'], Setting::get('crm.dashboard.sql_stages'));
        $this->assertSame($payload['crm.dashboard.pipeline_stages'], Setting::get('crm.dashboard.pipeline_stages'));
    }

    public function test_dashboard_persona_sections_match_role(): void
    {
        $this->seedRoles();

        $ceo = User::where('email', 'ceo@mss.test')->first();
        $marketing = User::where('email', 'marketing@mss.test')->first();
        $customerService = User::where('email', 'cs@mss.test')->first();
        $sales = User::where('email', 'sales@mss.test')->first();

        $ceoData = $this->actingAs($ceo)->getJson('/api/v1/dashboard/metrics')->json();
        $marketingData = $this->actingAs($marketing)->getJson('/api/v1/dashboard/metrics')->json();
        $csData = $this->actingAs($customerService)->getJson('/api/v1/dashboard/metrics')->json();
        $salesData = $this->actingAs($sales)->getJson('/api/v1/dashboard/metrics')->json();

        $this->assertSame('leadership', $ceoData['persona']);
        $this->assertContains('actions', $ceoData['sections']);
        $this->assertContains('marketing', $ceoData['sections']);
        $this->assertContains('checklist', $ceoData['sections']);

        $this->assertSame('marketing', $marketingData['persona']);
        $this->assertSame(['actions', 'kpis', 'mql_sql', 'marketing', 'funnel'], $marketingData['sections']);

        $this->assertSame('customer_service', $csData['persona']);
        $this->assertSame(['actions', 'checklist'], $csData['sections']);

        $this->assertSame('sales', $salesData['persona']);
        $this->assertContains('actions', $salesData['sections']);
        $this->assertContains('rep_activity', $salesData['sections']);
    }

    public function test_dashboard_action_items_expose_lead_route(): void
    {
        $this->seedRoles();
        $sales = User::where('email', 'sales@mss.test')->first();

        $lead = \App\Models\Lead::factory()->create([
            'owner_id' => $sales->id,
            'created_by' => $sales->id,
            'name' => 'Route Target Lead',
            'current_stage' => 'Contacted',
            'status' => 'contacted',
            'status_updated_at' => now()->subDays(9),
        ]);

        $items = $this->actingAs($sales)->getJson('/api/v1/dashboard/metrics')->json('action_items');
        $staleItem = collect($items)->firstWhere('kind', 'stale_lead');

        $this->assertNotNull($staleItem);
        $this->assertSame("/leads/{$lead->id}", $staleItem['route']);
        $this->assertSame('lead', $staleItem['entity_type']);
        $this->assertSame($lead->id, $staleItem['entity_id']);
    }

    public function test_dashboard_rep_activity_is_weekly_aggregate_only(): void
    {
        $this->seedRoles();
        $ceo = User::where('email', 'ceo@mss.test')->first();
        $sales = User::where('email', 'sales@mss.test')->first();
        $staff = User::where('email', 'staff@mss.test')->first();

        $salesLead = \App\Models\Lead::factory()->create([
            'owner_id' => $sales->id,
            'created_by' => $sales->id,
        ]);
        $staffLead = \App\Models\Lead::factory()->create([
            'owner_id' => $staff->id,
            'created_by' => $staff->id,
        ]);

        Engagement::create([
            'lead_id' => $salesLead->id,
            'user_id' => $sales->id,
            'summary' => 'Call #1',
            'occurred_at' => now()->startOfWeek()->addDay(),
        ]);
        Engagement::create([
            'lead_id' => $salesLead->id,
            'user_id' => $sales->id,
            'summary' => 'Call #2',
            'occurred_at' => now()->startOfWeek()->addDays(2),
        ]);
        Engagement::create([
            'lead_id' => $staffLead->id,
            'user_id' => $staff->id,
            'summary' => 'Follow-up',
            'occurred_at' => now()->startOfWeek()->addDay(),
        ]);

        $data = $this->actingAs($ceo)->getJson('/api/v1/dashboard/metrics')->json();
        $aggregate = $data['rep_activity_this_week'];
        $byRep = collect($aggregate)->keyBy('user_id');

        $this->assertSame(2, $byRep[$sales->id]['activity_count']);
        $this->assertSame(1, $byRep[$staff->id]['activity_count']);
        $this->assertContains(0, array_column($aggregate, 'activity_count'));
        $this->assertArrayNotHasKey('rep_activity_logs', $data);
        $this->assertArrayNotHasKey('summary', $byRep[$sales->id]);
    }

    public function test_dashboard_comparisons_include_qoq_and_yoy_deltas(): void
    {
        $this->seedRoles();
        $ceo = User::where('email', 'ceo@mss.test')->first();

        \App\Models\Lead::factory()->create([
            'owner_id' => $ceo->id,
            'created_by' => $ceo->id,
            'current_stage' => 'New',
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);

        $data = $this->actingAs($ceo)->getJson('/api/v1/dashboard/metrics')->json();

        $this->assertArrayHasKey('qoq', $data['comparisons']);
        $this->assertArrayHasKey('yoy', $data['comparisons']);
        $this->assertSame(1, $data['comparisons']['qoq']['leads_created']['current']);
        $this->assertSame(0, $data['comparisons']['qoq']['leads_created']['previous']);
        $this->assertEqualsWithDelta(100.0, (float) $data['comparisons']['qoq']['leads_created']['delta_pct'], 0.01);
        $this->assertEqualsWithDelta(100.0, (float) $data['comparisons']['yoy']['leads_created']['delta_pct'], 0.01);
    }

    public function test_dashboard_scope_counts_mql_sql_signed_revenue_and_pipeline(): void
    {
        $this->seedRoles();
        $sales = User::where('email', 'sales@mss.test')->first();
        $staff = User::where('email', 'staff@mss.test')->first();

        \App\Models\Lead::factory()->create([
            'owner_id' => $sales->id,
            'created_by' => $sales->id,
            'status' => 'new',
            'current_stage' => 'New',
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);
        \App\Models\Lead::factory()->create([
            'owner_id' => $sales->id,
            'created_by' => $sales->id,
            'status' => 'qualified',
            'current_stage' => 'Qualified',
            'created_at' => now()->subDays(2),
            'updated_at' => now()->subDays(2),
        ]);
        \App\Models\Lead::factory()->create([
            'owner_id' => $sales->id,
            'created_by' => $sales->id,
            'status' => 'contacted',
            'current_stage' => 'Proposal Sent',
            'created_at' => now()->subDays(3),
            'updated_at' => now()->subDays(3),
        ]);
        \App\Models\Lead::factory()->create([
            'owner_id' => $staff->id,
            'created_by' => $staff->id,
            'status' => 'contacted',
            'current_stage' => 'Proposal Sent',
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);

        $salesCustomer = Customer::create([
            'client_id' => 'CUS-SALES-0001',
            'name' => 'Sales Scoped Customer',
            'owner_id' => $sales->id,
            'created_by' => $sales->id,
        ]);
        $staffCustomer = Customer::create([
            'client_id' => 'CUS-STAFF-0001',
            'name' => 'Staff Scoped Customer',
            'owner_id' => $staff->id,
            'created_by' => $staff->id,
        ]);

        Agreement::create([
            'customer_id' => $salesCustomer->id,
            'type' => 'contract',
            'title' => 'Scoped Signed Contract',
            'status' => 'signed',
            'value' => 1200.00,
            'signed_at' => now()->subDay(),
            'created_by' => $sales->id,
        ]);
        Agreement::create([
            'customer_id' => $staffCustomer->id,
            'type' => 'contract',
            'title' => 'Out Of Scope Signed Contract',
            'status' => 'signed',
            'value' => 9999.00,
            'signed_at' => now()->subDay(),
            'created_by' => $staff->id,
        ]);

        $salesData = $this->actingAs($sales)->getJson('/api/v1/dashboard/metrics')->json();
        $ceo = User::where('email', 'ceo@mss.test')->first();
        $leadershipData = $this->actingAs($ceo)->getJson('/api/v1/dashboard/metrics')->json();

        $this->assertSame(1, $salesData['mql_sql']['mql']);
        $this->assertSame(2, $salesData['mql_sql']['sql']);
        $this->assertEqualsWithDelta(200.0, $salesData['mql_sql']['ratio_pct'], 0.01);
        $this->assertSame(0, $salesData['revenue']['signed']['count']);
        $this->assertSame(0, $salesData['revenue']['pipeline']['lead_count']);

        $this->assertSame(2, $leadershipData['revenue']['signed']['count']);
        $this->assertEqualsWithDelta(11199.0, (float) $leadershipData['revenue']['signed']['amount'], 0.01);
        $this->assertSame(2, $leadershipData['revenue']['pipeline']['lead_count']);
        $this->assertSame(['Proposal Sent', 'Negotiation'], $leadershipData['revenue']['pipeline']['stages']);
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
