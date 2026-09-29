<?php

namespace Tests\Unit\Domain\Crm;

use App\Domain\Crm\ChecklistService;
use App\Models\Customer;
use App\Models\PipelineStage;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChecklistServiceTest extends TestCase
{
    use RefreshDatabase;

    protected ChecklistService $service;
    protected Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new ChecklistService;
        $this->customer = Customer::create([
            'client_id' => 'CUS-2026-9001',
            'name' => 'Test Client',
        ]);
    }

    // UT-03: definitions come from dynamic settings
    public function test_reads_definitions_from_settings(): void
    {
        Setting::put('crm.checklists.contract_sign_off', [
            ['title' => 'Contract signed', 'is_required' => true],
            ['title' => 'Bank account opened', 'is_required' => true],
            ['title' => 'Advance payment', 'is_required' => false],
        ]);

        $defs = $this->service->definitionsForStage('contract_sign_off');

        $this->assertCount(3, $defs);
        $this->assertSame('Bank account opened', $defs[1]['title']);
    }

    // UT-04: conversion-time instantiation creates one completion per item, all undone
    public function test_instantiates_completions_for_customer(): void
    {
        PipelineStage::create(['code' => 'contract_sign_off', 'name' => 'Contract Sign-off']);

        Setting::put('crm.checklists.contract_sign_off', [
            ['title' => 'Contract signed'],
            ['title' => 'Bank account opened'],
        ]);

        $created = $this->service->instantiateForCustomer($this->customer, 'contract_sign_off');

        $this->assertSame(2, $created);
        $this->assertSame(2, $this->customer->checklistCompletions()->count());
        $this->customer->checklistCompletions->each(
            fn ($c) => $this->assertFalse($c->is_done)
        );
    }

    // Admin can remove items dynamically -> removed items are deactivated
    public function test_removed_settings_items_are_deactivated(): void
    {
        PipelineStage::create(['code' => 'onboarding', 'name' => 'Onboarding']);

        Setting::put('crm.checklists.onboarding', [
            ['title' => 'Client documents collected'],
            ['title' => 'Old item to remove'],
        ]);
        $this->service->instantiateForCustomer($this->customer, 'onboarding');

        // Admin removes one item from settings
        Setting::put('crm.checklists.onboarding', [
            ['title' => 'Client documents collected'],
        ]);
        $this->service->instantiateForCustomer($this->customer, 'onboarding');

        $active = \App\Models\StageChecklistItem::where('stage_id', PipelineStage::where('code', 'onboarding')->value('id'))
            ->where('is_active', true)
            ->get();

        $this->assertCount(1, $active);
        $this->assertSame('Client documents collected', $active->first()->title);
    }

    // Completion percent math
    public function test_completion_percent(): void
    {
        PipelineStage::create(['code' => 'launch', 'name' => 'Launch']);
        Setting::put('crm.checklists.launch', [
            ['title' => 'Dep A'],
            ['title' => 'Dep B'],
            ['title' => 'Dep C'],
            ['title' => 'Dep D'],
        ]);
        $this->service->instantiateForCustomer($this->customer, 'launch');

        $completions = $this->customer->checklistCompletions()->get();
        $completions[0]->update(['is_done' => true, 'done_at' => now()]);
        $completions[1]->update(['is_done' => true, 'done_at' => now()]);

        $report = $this->service->completionFor($this->customer);

        $this->assertSame(4, $report['Launch']['total']);
        $this->assertSame(2, $report['Launch']['done']);
        $this->assertSame(50, $report['Launch']['percent']);
    }
}
