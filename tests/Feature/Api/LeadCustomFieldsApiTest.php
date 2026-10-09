<?php

namespace Tests\Feature\Api;

use App\Models\CrmFieldDefinition;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadCustomFieldsApiTest extends TestCase
{
    use RefreshDatabase;

    protected function seedRoles(): void
    {
        $this->seed(\Database\Seeders\RoleAndUserSeeder::class);
    }

    public function test_leads_schema_includes_active_lead_custom_fields(): void
    {
        $this->seedRoles();
        $sales = User::where('email', 'sales@mss.test')->first();

        CrmFieldDefinition::create([
            'entity' => 'lead',
            'field_key' => 'campaign_code',
            'label' => 'Campaign Code',
            'field_type' => 'text',
            'sort_order' => 1,
            'is_active' => true,
        ]);
        CrmFieldDefinition::create([
            'entity' => 'lead',
            'field_key' => 'inactive_field',
            'label' => 'Hidden',
            'field_type' => 'text',
            'is_active' => false,
        ]);

        $res = $this->actingAs($sales)->getJson('/api/v1/leads/schema');

        $res->assertOk();
        $keys = collect($res->json('custom_fields.lead'))->pluck('field_key')->all();
        $this->assertContains('campaign_code', $keys);
        $this->assertNotContains('inactive_field', $keys);
    }

    public function test_create_lead_persists_custom_fields(): void
    {
        $this->seedRoles();
        $sales = User::where('email', 'sales@mss.test')->first();

        CrmFieldDefinition::create([
            'entity' => 'lead',
            'field_key' => 'campaign_code',
            'label' => 'Campaign Code',
            'field_type' => 'text',
            'is_active' => true,
        ]);

        $res = $this->actingAs($sales)->postJson('/api/v1/leads', [
            'name' => 'Custom Field Lead',
            'custom_fields' => ['campaign_code' => 'EVT-2026'],
        ]);

        $res->assertCreated()
            ->assertJsonPath('custom_fields.campaign_code', 'EVT-2026');

        $this->assertDatabaseHas('leads', [
            'name' => 'Custom Field Lead',
        ]);
        $this->assertSame('EVT-2026', $res->json('custom_fields.campaign_code'));
    }

    public function test_create_lead_rejects_unknown_custom_field_key(): void
    {
        $this->seedRoles();
        $sales = User::where('email', 'sales@mss.test')->first();

        $this->actingAs($sales)->postJson('/api/v1/leads', [
            'name' => 'Bad Custom',
            'custom_fields' => ['not_defined' => 'x'],
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['custom_fields.not_defined']);
    }

    public function test_create_lead_rejects_invalid_select_option(): void
    {
        $this->seedRoles();
        $sales = User::where('email', 'sales@mss.test')->first();

        CrmFieldDefinition::create([
            'entity' => 'lead',
            'field_key' => 'tier',
            'label' => 'Tier',
            'field_type' => 'select',
            'options' => ['Gold', 'Silver'],
            'is_active' => true,
        ]);

        $this->actingAs($sales)->postJson('/api/v1/leads', [
            'name' => 'Bad Select',
            'custom_fields' => ['tier' => 'Platinum'],
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['custom_fields.tier']);
    }

    public function test_lead_index_returns_custom_fields_on_each_row(): void
    {
        $this->seedRoles();
        $sales = User::where('email', 'sales@mss.test')->first();

        CrmFieldDefinition::create([
            'entity' => 'lead',
            'field_key' => 'campaign_code',
            'label' => 'Campaign Code',
            'field_type' => 'text',
            'is_active' => true,
        ]);

        $this->actingAs($sales)->postJson('/api/v1/leads', [
            'name' => 'Listed Lead',
            'custom_fields' => ['campaign_code' => 'WEB-01'],
        ])->assertCreated();

        $res = $this->actingAs($sales)->getJson('/api/v1/leads');
        $row = collect($res->json('data'))->firstWhere('name', 'Listed Lead');
        $this->assertNotNull($row);
        $this->assertSame('WEB-01', $row['custom_fields']['campaign_code'] ?? null);
    }

    public function test_per_page_is_capped_at_fifty(): void
    {
        $this->seedRoles();
        $sales = User::where('email', 'sales@mss.test')->first();

        $res = $this->actingAs($sales)->getJson('/api/v1/leads?per_page=500');
        $this->assertSame(50, $res->json('per_page'));
    }
}
