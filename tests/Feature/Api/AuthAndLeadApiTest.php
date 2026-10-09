<?php

namespace Tests\Feature\Api;

use App\Models\Lead;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthAndLeadApiTest extends TestCase
{
    use RefreshDatabase;

    protected function seedRoles(): void
    {
        $this->seed(\Database\Seeders\RoleAndUserSeeder::class);
    }

    // FT-01: login returns token
    public function test_login_returns_token(): void
    {
        $this->seedRoles();

        $res = $this->postJson('/api/v1/auth/login', [
            'email' => 'sales@mss.test',
            'password' => 'password',
        ]);

        $res->assertOk()
            ->assertJsonStructure(['token', 'user' => ['id', 'name', 'role' => ['code']]]);
    }

    // FT-01b: wrong password rejected
    public function test_login_rejects_wrong_password(): void
    {
        $this->seedRoles();

        $this->postJson('/api/v1/auth/login', [
            'email' => 'sales@mss.test',
            'password' => 'wrong',
        ])->assertStatus(422);
    }

    // FT-02: protected route requires auth
    public function test_me_requires_authentication(): void
    {
        $this->getJson('/api/v1/auth/me')->assertStatus(401);
    }

    // FT-03: sales creates a lead, owner auto-set
    public function test_sales_can_create_lead(): void
    {
        $this->seedRoles();
        $sales = User::where('email', 'sales@mss.test')->first();

        $res = $this->actingAs($sales)
            ->postJson('/api/v1/leads', [
                'name' => 'Prospect A',
                'company' => 'Acme',
                'source' => 'viber',
            ]);

        $res->assertCreated()
            ->assertJsonPath('name', 'Prospect A')
            ->assertJsonPath('owner.id', $sales->id);
    }

    // FT-04: staff cannot update someone else's lead (scope rule)
    public function test_staff_cannot_update_others_lead(): void
    {
        $this->seedRoles();
        $sales = User::where('email', 'sales@mss.test')->first();
        $staff = User::where('email', 'staff@mss.test')->first();

        $lead = Lead::factory()->create(['owner_id' => $sales->id]);

        $this->actingAs($staff)
            ->putJson("/api/v1/leads/{$lead->id}", ['status' => 'qualified'])
            ->assertStatus(403);
    }

    // FT-05: engagement refreshes stale timer
    public function test_engagement_refreshes_stale_timer(): void
    {
        $this->seedRoles();
        $sales = User::where('email', 'sales@mss.test')->first();

        $lead = Lead::factory()->stale(10)->create(['owner_id' => $sales->id]);
        $this->assertTrue($lead->status_updated_at->lt(now()->subDays(5)));

        $this->actingAs($sales)
            ->postJson("/api/v1/leads/{$lead->id}/engagements", [
                'summary' => 'Called client, discussing scope',
            ])->assertCreated();

        $this->assertTrue($lead->fresh()->status_updated_at->gt(now()->subMinutes(5)));
    }

    // FT-06: conversion generates Client ID, products, checklists
    public function test_convert_lead_to_customer(): void
    {
        $this->seedRoles();
        $sales = User::where('email', 'sales@mss.test')->first();

        \App\Models\PipelineStage::firstOrCreate(['code' => 'onboarding'], ['name' => 'Onboarding']);
        \App\Models\PipelineStage::firstOrCreate(['code' => 'contract_sign_off'], ['name' => 'Contract Sign-off']);
        \App\Models\Setting::put('crm.checklists.onboarding', [['title' => 'Docs collected']]);
        \App\Models\Setting::put('crm.checklists.contract_sign_off', [['title' => 'NDA signed']]);
        $svc = \App\Models\ProductService::create(['code' => 'APP', 'name' => 'App Dev', 'type' => 'service']);

        $lead = Lead::factory()->create(['owner_id' => $sales->id, 'status' => 'qualified']);

        $res = $this->actingAs($sales)
            ->postJson("/api/v1/leads/{$lead->id}/convert", ['product_service_ids' => [$svc->id]]);

        $res->assertCreated()
            ->assertJsonStructure(['id', 'client_id', 'name']);
        $this->assertMatchesRegularExpression('/^\d{6}_C\d+$/', $res->json('client_id'));
        $this->assertDatabaseHas('customer_products', [
            'customer_id' => $res->json('id'),
            'product_service_id' => $svc->id,
        ]);
        $this->assertDatabaseHas('checklist_completions', ['customer_id' => $res->json('id')]);
    }

    // FT-13: supervisor sees only team leads
    public function test_supervisor_sees_team_scope_only(): void
    {
        $this->seedRoles();
        $supervisor = User::where('email', 'supervisor@mss.test')->first();
        $sales = User::where('email', 'sales@mss.test')->first(); // same team
        $other = User::factory()->create(['role_id' => Role::where('code', Role::SALES)->value('id')]); // no team

        Lead::factory()->create(['owner_id' => $sales->id, 'name' => 'Team lead']);
        Lead::factory()->create(['owner_id' => $other->id, 'name' => 'Outsider lead']);

        $res = $this->actingAs($supervisor)->getJson('/api/v1/leads');

        $names = collect($res->json('data'))->pluck('name')->all();
        $this->assertContains('Team lead', $names);
        $this->assertNotContains('Outsider lead', $names);
    }

    // UT-08 / FT-12 scope: senior staff sees downstream works
    public function test_senior_staff_sees_downstream_lead(): void
    {
        $this->seedRoles();
        $senior = User::where('email', 'senior.staff@mss.test')->first();
        $staff = User::where('email', 'staff@mss.test')->first(); // reports to senior staff

        Lead::factory()->create(['owner_id' => $staff->id, 'name' => 'Downstream work']);

        $res = $this->actingAs($senior)->getJson('/api/v1/leads');
        $names = collect($res->json('data'))->pluck('name')->all();
        $this->assertContains('Downstream work', $names);
    }
}
