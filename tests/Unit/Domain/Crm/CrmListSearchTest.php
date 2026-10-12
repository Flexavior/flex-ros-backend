<?php

namespace Tests\Unit\Domain\Crm;

use App\Domain\Crm\CrmListSearch;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CrmListSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_search_is_case_insensitive_substring(): void
    {
        $this->seed(\Database\Seeders\RoleAndUserSeeder::class);
        $sales = User::where('email', 'sales@mss.test')->first();

        Customer::create([
            'client_id' => '250506_C73',
            'name' => 'Aung Pyi Hae Co.,Ltd',
            'email' => 'aung.pyi.hae@gmail.com',
            'owner_id' => $sales->id,
            'status' => 'active',
        ]);

        $ids = CrmListSearch::applyCustomerSearch(Customer::query(), 'GMAIL')
            ->pluck('id')
            ->all();

        $this->assertCount(1, $ids);
    }

    public function test_lead_search_matches_company(): void
    {
        $this->seed(\Database\Seeders\RoleAndUserSeeder::class);
        $sales = User::where('email', 'sales@mss.test')->first();

        Lead::factory()->create([
            'owner_id' => $sales->id,
            'company' => 'Career Network International',
            'name' => 'Contact',
        ]);

        $count = CrmListSearch::applyLeadSearch(Lead::query(), 'Career Network')->count();
        $this->assertSame(1, $count);
    }
}
