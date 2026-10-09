<?php

namespace Tests\Feature\Api;

use App\Models\Customer;
use App\Models\Lead;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadJourneyPhase15ApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_lead_contacts_can_be_synced_on_update(): void
    {
        $this->seed(\Database\Seeders\RoleAndUserSeeder::class);
        $sales = User::where('email', 'sales@mss.test')->first();
        $lead = Lead::factory()->create(['owner_id' => $sales->id]);

        $res = $this->actingAs($sales)->putJson("/api/v1/leads/{$lead->id}", [
            'contacts' => [
                [
                    'name' => 'Alice',
                    'role' => 'Owner',
                    'email' => 'alice@example.test',
                    'phone' => '09111',
                    'viber_id' => 'alice.viber',
                    'is_primary' => true,
                ],
                [
                    'name' => 'Bob',
                    'role' => 'Manager',
                    'email' => 'bob@example.test',
                    'phone' => '09222',
                    'is_primary' => false,
                ],
            ],
        ]);

        $res->assertOk()
            ->assertJsonCount(2, 'contacts')
            ->assertJsonPath('contacts.0.is_primary', true);

        $this->assertDatabaseHas('lead_contacts', [
            'lead_id' => $lead->id,
            'name' => 'Alice',
            'is_primary' => true,
        ]);
    }

    public function test_lead_contacts_reject_multiple_primary_flags(): void
    {
        $this->seed(\Database\Seeders\RoleAndUserSeeder::class);
        $sales = User::where('email', 'sales@mss.test')->first();
        $lead = Lead::factory()->create(['owner_id' => $sales->id]);

        $this->actingAs($sales)->putJson("/api/v1/leads/{$lead->id}", [
            'contacts' => [
                ['name' => 'A', 'is_primary' => true],
                ['name' => 'B', 'is_primary' => true],
            ],
        ])->assertStatus(422);
    }

    public function test_lead_qualify_idle_alert_counts_non_progress_touches(): void
    {
        $this->seed(\Database\Seeders\RoleAndUserSeeder::class);
        $sales = User::where('email', 'sales@mss.test')->first();
        $lead = Lead::factory()->create(['owner_id' => $sales->id]);

        foreach (range(1, 4) as $i) {
            $lead->engagements()->create([
                'user_id' => $sales->id,
                'summary' => "Touch {$i}",
                'activity_outcome' => 'Connected',
                'occurred_at' => now(),
            ]);
        }
        $lead->engagements()->create([
            'user_id' => $sales->id,
            'summary' => 'Progress touch',
            'activity_outcome' => 'Demo booked',
            'occurred_at' => now(),
        ]);

        $res = $this->actingAs($sales)->getJson("/api/v1/leads/{$lead->id}");

        $res->assertOk()
            ->assertJsonPath('idle_touch_count', 4)
            ->assertJsonPath('needs_qualify_review', true)
            ->assertJsonPath('qualify_idle_alert', true);
    }

    public function test_custom_qualify_progress_outcomes_affect_alert_logic(): void
    {
        $this->seed(\Database\Seeders\RoleAndUserSeeder::class);
        $sales = User::where('email', 'sales@mss.test')->first();
        $lead = Lead::factory()->create(['owner_id' => $sales->id]);
        Setting::put('crm.qualify', [
            'max_idle_touches' => 4,
            'progress_outcomes' => ['Connected'],
        ]);

        foreach (range(1, 4) as $i) {
            $lead->engagements()->create([
                'user_id' => $sales->id,
                'summary' => "Touch {$i}",
                'activity_outcome' => 'Connected',
                'occurred_at' => now(),
            ]);
        }

        $res = $this->actingAs($sales)->getJson("/api/v1/leads/{$lead->id}");
        $res->assertOk()
            ->assertJsonPath('idle_touch_count', 0)
            ->assertJsonPath('needs_qualify_review', false);
    }

    public function test_customer_status_and_segment_fields_updatable(): void
    {
        $this->seed(\Database\Seeders\RoleAndUserSeeder::class);
        $sales = User::where('email', 'sales@mss.test')->first();
        $customer = Customer::create([
            'client_id' => '261009_C900',
            'name' => 'Test Co',
            'owner_id' => $sales->id,
            'status' => 'onboarding',
        ]);

        $this->actingAs($sales)
            ->putJson("/api/v1/customers/{$customer->id}", [
                'status' => 'active',
                'industry' => 'Education',
                'geo_location' => 'Yangon',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'active')
            ->assertJsonPath('industry', 'Education');
    }

    public function test_customer_address_is_updatable(): void
    {
        $this->seed(\Database\Seeders\RoleAndUserSeeder::class);
        $sales = User::where('email', 'sales@mss.test')->first();
        $customer = Customer::create([
            'client_id' => '261009_C999',
            'name' => 'Customer A',
            'owner_id' => $sales->id,
        ]);

        $this->actingAs($sales)->putJson("/api/v1/customers/{$customer->id}", [
            'address' => 'No. 1, Sample Street, Yangon',
        ])->assertOk()
            ->assertJsonPath('address', 'No. 1, Sample Street, Yangon');
    }

    public function test_convert_is_blocked_before_qualified_stage(): void
    {
        $this->seed(\Database\Seeders\RoleAndUserSeeder::class);
        $sales = User::where('email', 'sales@mss.test')->first();
        $lead = Lead::factory()->create([
            'owner_id' => $sales->id,
            'current_stage' => 'New',
            'status' => 'new',
        ]);

        $this->actingAs($sales)
            ->postJson("/api/v1/leads/{$lead->id}/convert", [])
            ->assertStatus(422);
    }
}
