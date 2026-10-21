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
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Mobile App Development');
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
            ->assertJsonCount(2, 'data');
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
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Mobile App Development');
    }

    public function test_index_is_paginated_with_page_and_per_page(): void
    {
        $this->seed(\Database\Seeders\RoleAndUserSeeder::class);
        $user = User::where('email', 'sales@mss.test')->first();

        for ($i = 1; $i <= 3; $i++) {
            ProductService::create(['code' => "C{$i}", 'name' => "Item {$i}", 'type' => 'service']);
        }

        $this->actingAs($user)
            ->getJson('/api/v1/products-services?page=1&per_page=2')
            ->assertOk()
            ->assertJsonPath('current_page', 1)
            ->assertJsonPath('per_page', 2)
            ->assertJsonPath('total', 3)
            ->assertJsonPath('last_page', 2)
            ->assertJsonCount(2, 'data');
    }
}
