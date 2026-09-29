<?php

namespace Tests\Unit\Domain\Crm;

use App\Domain\Crm\ClientIdGenerator;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientIdGeneratorTest extends TestCase
{
    use RefreshDatabase;

    // UT-01: ID format {PREFIX}-YYYY-####
    public function test_generates_id_in_expected_format(): void
    {
        $generator = new ClientIdGenerator;

        $clientId = $generator->generate();

        $this->assertMatchesRegularExpression(
            '/^CUS-\d{4}-\d{4}$/',
            $clientId,
            'Client ID must match CUS-YYYY-#### format'
        );
    }

    // UT-02a: sequence increments
    public function test_sequence_increments(): void
    {
        \App\Models\Customer::create([
            'client_id' => sprintf('CUS-%d-0001', now()->year),
            'name' => 'First Client',
        ]);

        $next = (new ClientIdGenerator)->generate();

        $this->assertSame(sprintf('CUS-%d-0002', now()->year), $next);
    }

    // UT-02b: year change resets sequence
    public function test_sequence_resets_per_year(): void
    {
        \App\Models\Customer::create([
            'client_id' => 'CUS-2020-0042',
            'name' => 'Old Client',
        ]);

        $next = (new ClientIdGenerator)->generate();

        $this->assertSame(sprintf('CUS-%d-0001', now()->year), $next);
    }

    // Prefix is settings-driven
    public function test_prefix_is_settings_driven(): void
    {
        Setting::put('crm.client_id_prefix', 'CLT');

        $next = (new ClientIdGenerator)->generate();

        $this->assertStringStartsWith('CLT-', $next);
    }
}
