<?php

namespace Tests\Feature\Api;

use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadCaptureFieldsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_lead_with_segment_industry_geo(): void
    {
        $this->seed(\Database\Seeders\RoleAndUserSeeder::class);
        $sales = User::where('email', 'sales@mss.test')->first();

        $res = $this->actingAs($sales)->postJson('/api/v1/leads', [
            'name' => 'Capture Lead',
            'customer_segment' => 'SME',
            'industry' => 'Education',
            'geo_location' => 'Yangon',
        ]);

        $res->assertCreated()
            ->assertJsonPath('customer_segment', 'SME')
            ->assertJsonPath('industry', 'Education')
            ->assertJsonPath('geo_location', 'Yangon');
    }

    public function test_schema_exposes_industry_and_geo_picklists(): void
    {
        $this->seed(\Database\Seeders\RoleAndUserSeeder::class);
        $sales = User::where('email', 'sales@mss.test')->first();

        $res = $this->actingAs($sales)->getJson('/api/v1/leads/schema');
        $res->assertOk();
        $this->assertNotEmpty($res->json('picklists.industry'));
        $this->assertNotEmpty($res->json('picklists.geo_location'));
        $this->assertNotEmpty($res->json('picklists.customer_segment'));
    }

    public function test_rejects_unknown_industry(): void
    {
        $this->seed(\Database\Seeders\RoleAndUserSeeder::class);
        $sales = User::where('email', 'sales@mss.test')->first();

        $this->actingAs($sales)->postJson('/api/v1/leads', [
            'name' => 'Bad Industry',
            'industry' => 'Not A Real Industry',
        ])->assertStatus(422);
    }

    public function test_update_lead_qualify_picklists(): void
    {
        $this->seed(\Database\Seeders\RoleAndUserSeeder::class);
        $sales = User::where('email', 'sales@mss.test')->first();
        $lead = Lead::factory()->create(['owner_id' => $sales->id, 'name' => 'Qualify Fields']);

        $this->actingAs($sales)
            ->putJson("/api/v1/leads/{$lead->id}", [
                'interest_level' => 'Hot',
                'buying_timeline' => '1 Month',
                'primary_contact_method' => 'Viber',
                'contact_role' => 'Director',
            ])
            ->assertOk()
            ->assertJsonPath('interest_level', 'Hot')
            ->assertJsonPath('buying_timeline', '1 Month')
            ->assertJsonPath('primary_contact_method', 'Viber')
            ->assertJsonPath('contact_role', 'Director');
    }

    public function test_lead_show_includes_legacy_picklist_warnings(): void
    {
        $this->seed(\Database\Seeders\RoleAndUserSeeder::class);
        $sales = User::where('email', 'sales@mss.test')->first();

        $lead = Lead::factory()->create([
            'owner_id' => $sales->id,
            'name' => 'Legacy Picklist Lead',
            'industry' => 'Pre-Migration Industry Label',
        ]);

        $this->actingAs($sales)
            ->getJson("/api/v1/leads/{$lead->id}")
            ->assertOk()
            ->assertJsonPath('legacy_picklist_warnings.0.field', 'industry')
            ->assertJsonPath('legacy_picklist_warnings.0.value', 'Pre-Migration Industry Label');
    }
}
