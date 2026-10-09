<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadListValidationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_invalid_industry_filter_rejected(): void
    {
        $this->seed(\Database\Seeders\RoleAndUserSeeder::class);
        $sales = User::where('email', 'sales@mss.test')->first();

        $this->actingAs($sales)
            ->getJson('/api/v1/leads?industry=NotInCatalog')
            ->assertStatus(422);
    }

    public function test_per_page_capped_at_fifty(): void
    {
        $this->seed(\Database\Seeders\RoleAndUserSeeder::class);
        $sales = User::where('email', 'sales@mss.test')->first();

        $res = $this->actingAs($sales)->getJson('/api/v1/leads?per_page=999');
        $this->assertSame(50, $res->json('per_page'));
    }
}
