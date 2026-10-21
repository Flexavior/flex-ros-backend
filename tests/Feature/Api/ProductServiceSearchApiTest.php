<?php

namespace Tests\Feature\Api;

use App\Models\ProductService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductServiceSearchApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_requires_all_keyword_tokens_in_name(): void
    {
        $this->seed(\Database\Seeders\RoleAndUserSeeder::class);
        $user = User::where('email', 'sales@mss.test')->first();

        ProductService::create(['code' => 'A', 'name' => 'Mobile App Development', 'type' => 'service']);
        ProductService::create(['code' => 'B', 'name' => 'Website Design', 'type' => 'service']);

        $this->actingAs($user)
            ->getJson('/api/v1/products-services?search=mobile app')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.name', 'Mobile App Development');
    }

    public function test_search_with_fewer_than_three_characters_does_not_filter(): void
    {
        $this->seed(\Database\Seeders\RoleAndUserSeeder::class);
        $user = User::where('email', 'sales@mss.test')->first();

        ProductService::create(['code' => 'A', 'name' => 'Mobile App Development', 'type' => 'service']);
        ProductService::create(['code' => 'B', 'name' => 'Website Design', 'type' => 'service']);

        $this->actingAs($user)
            ->getJson('/api/v1/products-services?search=mo')
            ->assertOk()
            ->assertJsonCount(2);
    }

    public function test_search_single_token_of_three_or_more_characters(): void
    {
        $this->seed(\Database\Seeders\RoleAndUserSeeder::class);
        $user = User::where('email', 'sales@mss.test')->first();

        ProductService::create(['code' => 'A', 'name' => 'Mobile App Development', 'type' => 'service']);
        ProductService::create(['code' => 'B', 'name' => 'Website Design', 'type' => 'service']);

        $this->actingAs($user)
            ->getJson('/api/v1/products-services?search=mob')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.name', 'Mobile App Development');
    }
}
