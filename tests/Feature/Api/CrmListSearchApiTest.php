<?php

namespace Tests\Feature\Api;

use App\Models\Customer;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CrmListSearchApiTest extends TestCase
{
    use RefreshDatabase;

    protected function seedRoles(): void
    {
        $this->seed(\Database\Seeders\RoleAndUserSeeder::class);
    }

    public function test_customer_list_search_by_client_id(): void
    {
        $this->seedRoles();
        $sales = User::where('email', 'sales@mss.test')->first();

        Customer::create([
            'owner_id' => $sales->id,
            'created_by' => $sales->id,
            'client_id' => '250506_C73',
            'name' => 'Visible Co',
            'status' => 'active',
        ]);
        Customer::create([
            'owner_id' => $sales->id,
            'created_by' => $sales->id,
            'client_id' => '999999_Z99',
            'name' => 'Hidden Co',
            'status' => 'active',
        ]);

        $res = $this->actingAs($sales)->getJson('/api/v1/customers?q=250506_C73');
        $res->assertOk();
        $this->assertSame(1, $res->json('total'));
        $this->assertSame('250506_C73', $res->json('data.0.client_id'));
    }

    public function test_customer_list_search_by_phone_fragment(): void
    {
        $this->seedRoles();
        $sales = User::where('email', 'sales@mss.test')->first();

        Customer::create([
            'owner_id' => $sales->id,
            'created_by' => $sales->id,
            'phone' => '9790458459',
            'name' => 'Phone Match',
            'client_id' => '250506_C74',
            'status' => 'active',
        ]);
        Customer::create([
            'owner_id' => $sales->id,
            'created_by' => $sales->id,
            'phone' => '111',
            'name' => 'Other',
            'client_id' => '250506_C75',
            'status' => 'active',
        ]);

        $res = $this->actingAs($sales)->getJson('/api/v1/customers?q=979045');
        $res->assertOk();
        $this->assertSame(1, $res->json('total'));
    }

    public function test_lead_list_search_by_name_and_linked_client_id(): void
    {
        $this->seedRoles();
        $sales = User::where('email', 'sales@mss.test')->first();

        $customer = Customer::create([
            'owner_id' => $sales->id,
            'created_by' => $sales->id,
            'client_id' => '250519_C75',
            'name' => 'Cust',
            'status' => 'active',
        ]);

        Lead::factory()->create([
            'owner_id' => $sales->id,
            'created_by' => $sales->id,
            'name' => 'Ko Bo Bo',
            'company' => 'Pyae Wa Kywal',
            'customer_id' => $customer->id,
        ]);
        Lead::factory()->create([
            'owner_id' => $sales->id,
            'created_by' => $sales->id,
            'name' => 'Unrelated Lead',
            'company' => 'Other',
        ]);

        $this->actingAs($sales)->getJson('/api/v1/leads?q=Ko Bo')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.name', 'Ko Bo Bo');

        $this->actingAs($sales)->getJson('/api/v1/leads?q=250519_C75')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.name', 'Ko Bo Bo');
    }
}
