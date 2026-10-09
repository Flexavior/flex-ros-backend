<?php

namespace Tests\Feature\Api;

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
}
