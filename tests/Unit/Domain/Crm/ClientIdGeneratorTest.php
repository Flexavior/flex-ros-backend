<?php

namespace Tests\Unit\Domain\Crm;

use App\Domain\Crm\ClientIdGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientIdGeneratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_generates_id_in_expected_format(): void
    {
        $generator = new ClientIdGenerator;
        $clientId = $generator->generate();

        $this->assertMatchesRegularExpression('/^\d{6}_C\d+$/', $clientId);
        $this->assertStringStartsWith(now()->format('ymd').'_C', $clientId);
    }

    public function test_sequence_increments_from_legacy_ids(): void
    {
        \App\Models\Customer::create([
            'client_id' => '240213_C26',
            'name' => 'Legacy',
        ]);

        $next = (new ClientIdGenerator)->generate();
        $this->assertSame(now()->format('ymd').'_C27', $next);
    }

    public function test_manual_id_must_match_pattern(): void
    {
        $generator = new ClientIdGenerator;
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $generator->validateNewFormat('CUS-2026-0001');
    }

    public function test_assert_unique_rejects_duplicate(): void
    {
        \App\Models\Customer::create([
            'client_id' => '261009_C101',
            'name' => 'Taken',
        ]);

        $generator = new ClientIdGenerator;
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $generator->assertUnique('261009_C101');
    }
}
