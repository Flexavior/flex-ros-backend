<?php

namespace Tests\Unit\Domain\Crm;

use App\Domain\Crm\LeadPipelineSyncService;
use App\Models\Lead;
use Tests\TestCase;

class LeadPipelineSyncServiceTest extends TestCase
{
    private LeadPipelineSyncService $sync;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sync = new LeadPipelineSyncService;
    }

    public function test_current_stage_syncs_status_on_write(): void
    {
        $data = ['current_stage' => 'Proposal Sent'];
        $this->sync->syncWritableAttributes($data);
        $this->assertSame(Lead::STATUS_QUALIFIED, $data['status']);
    }

    public function test_status_syncs_current_stage_on_write(): void
    {
        $data = ['status' => Lead::STATUS_APPOINTMENT];
        $this->sync->syncWritableAttributes($data);
        $this->assertSame('Demo / Meeting', $data['current_stage']);
    }

    public function test_enrich_api_payload_from_legacy_status_only(): void
    {
        $payload = $this->sync->enrichApiPayload([
            'status' => 'qualified',
            'current_stage' => null,
        ]);
        $this->assertSame('Qualified', $payload['current_stage']);
    }

    public function test_stage_id_stripped_from_api_payload(): void
    {
        $payload = $this->sync->enrichApiPayload(['stage_id' => 99, 'status' => 'new']);
        $this->assertArrayNotHasKey('stage_id', $payload);
    }
}
