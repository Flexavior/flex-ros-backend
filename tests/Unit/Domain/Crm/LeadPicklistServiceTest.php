<?php

namespace Tests\Unit\Domain\Crm;

use App\Domain\Crm\LeadPicklistService;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadPicklistServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_defaults_include_industry_and_geo(): void
    {
        $service = new LeadPicklistService;
        $all = $service->all();

        $this->assertArrayHasKey('industry', $all);
        $this->assertArrayHasKey('geo_location', $all);
        $this->assertArrayHasKey('customer_segment', $all);
        $this->assertContains('Banking & Finance', $all['industry']);
        $this->assertContains('Yangon', $all['geo_location']);
        $this->assertContains('SME', $all['customer_segment']);
    }

    public function test_settings_override_defaults(): void
    {
        Setting::put(LeadPicklistService::SETTINGS_KEY, [
            'industry' => ['Custom Industry'],
            'geo_location' => ['Custom Geo'],
        ]);

        $service = new LeadPicklistService;
        $this->assertSame(['Custom Industry'], $service->options('industry'));
        $this->assertSame(['Custom Geo'], $service->options('geo_location'));
        // Unspecified keys still come from defaults
        $this->assertContains('SME', $service->options('customer_segment'));
    }

    public function test_put_persists_to_settings(): void
    {
        $service = new LeadPicklistService;
        $service->put([
            'industry' => ['Fintech', 'Edtech'],
            'geo_location' => ['Yangon', 'Mandalay'],
        ]);

        $stored = Setting::get(LeadPicklistService::SETTINGS_KEY);
        $this->assertSame(['Fintech', 'Edtech'], $stored['industry']);
        $this->assertSame(['Yangon', 'Mandalay'], $stored['geo_location']);
    }

    public function test_put_rejects_unknown_catalogue_key(): void
    {
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        (new LeadPicklistService)->put(['evil_key' => ['x']]);
    }

    public function test_stored_unknown_keys_stripped_on_read(): void
    {
        Setting::put(LeadPicklistService::SETTINGS_KEY, [
            'industry' => ['Education'],
            'injected' => ['malicious'],
        ]);

        $all = (new LeadPicklistService)->all();
        $this->assertArrayNotHasKey('injected', $all);
        $this->assertSame(['Education'], $all['industry']);
    }
}
