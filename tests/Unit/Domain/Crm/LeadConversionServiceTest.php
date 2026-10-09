<?php

namespace Tests\Unit\Domain\Crm;

use App\Domain\Crm\LeadConversionService;
use App\Models\Lead;
use App\Models\ProductService;
use App\Models\PipelineStage;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadConversionServiceTest extends TestCase
{
    use RefreshDatabase;

    // UT-05: conversion closes the lead
    public function test_conversion_marks_lead_converted(): void
    {
        $user = User::factory()->create();
        PipelineStage::create(['code' => 'onboarding', 'name' => 'Onboarding']);
        PipelineStage::create(['code' => 'contract_sign_off', 'name' => 'Contract Sign-off']);
        Setting::put('crm.checklists.onboarding', [['title' => 'Docs collected']]);
        Setting::put('crm.checklists.contract_sign_off', [['title' => 'Contract signed']]);

        $lead = Lead::factory()->create([
            'name' => 'Acme Corp',
            'status' => 'qualified',
            'owner_id' => $user->id,
        ]);

        $service = app(LeadConversionService::class);
        $customer = $service->convert($lead, [], $user->id);

        $this->assertSame(Lead::STATUS_CONVERTED, $lead->fresh()->status);
        $this->assertSame($customer->id, $lead->fresh()->customer_id);
    }

    // UT-04 (integration): checklists instantiated on conversion
    public function test_conversion_instantiates_dynamic_checklists(): void
    {
        $user = User::factory()->create();
        PipelineStage::create(['code' => 'onboarding', 'name' => 'Onboarding']);
        PipelineStage::create(['code' => 'contract_sign_off', 'name' => 'Contract Sign-off']);
        Setting::put('crm.checklists.onboarding', [
            ['title' => 'Client documents collected'],
            ['title' => 'Bank account'],
        ]);
        Setting::put('crm.checklists.contract_sign_off', [
            ['title' => 'NDA signed'],
            ['title' => 'Contract signed'],
        ]);

        $lead = Lead::factory()->create(['owner_id' => $user->id]);
        $customer = app(LeadConversionService::class)->convert($lead, [], $user->id);

        $this->assertSame(4, $customer->checklistCompletions()->count());
    }

    // Products/services are mapped to the new customer
    public function test_conversion_maps_products(): void
    {
        $user = User::factory()->create();
        $svcA = ProductService::create(['code' => 'APP', 'name' => 'Mobile App Dev', 'type' => 'service']);
        $svcB = ProductService::create(['code' => 'WEB', 'name' => 'Website', 'type' => 'service']);

        $lead = Lead::factory()->create(['owner_id' => $user->id]);
        $customer = app(LeadConversionService::class)->convert($lead, [$svcA->id, $svcB->id], $user->id);

        $this->assertSame(2, $customer->products()->count());
        $this->assertTrue($customer->products->contains('id', $svcA->id));
    }

    // Client ID format + uniqueness on conversion
    public function test_conversion_generates_client_id(): void
    {
        $user = User::factory()->create();
        $lead = Lead::factory()->create(['owner_id' => $user->id]);

        $customer = app(LeadConversionService::class)->convert($lead, [], $user->id);

        $this->assertMatchesRegularExpression('/^\d{6}_C\d+$/', $customer->client_id);
    }

    // Double conversion is rejected
    public function test_double_conversion_throws(): void
    {
        $user = User::factory()->create();
        $lead = Lead::factory()->create(['owner_id' => $user->id]);
        $service = app(LeadConversionService::class);

        $service->convert($lead, [], $user->id);

        $this->expectException(\InvalidArgumentException::class);
        $service->convert($lead->fresh(), [], $user->id);
    }
}
