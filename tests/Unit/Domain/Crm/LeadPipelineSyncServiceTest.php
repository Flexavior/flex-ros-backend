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

    public function test_api_payload_strips_legacy_source_and_stage_id(): void
    {
        $payload = $this->sync->enrichApiPayload([
            'stage_id' => 99,
            'status' => 'new',
            'source' => 'Referrals',
            'lead_source' => 'Referrals',
        ]);
        $this->assertArrayNotHasKey('stage_id', $payload);
        $this->assertArrayNotHasKey('source', $payload);
        $this->assertSame('Referrals', $payload['lead_source']);
    }

    public function test_not_qualified_maps_to_lost_status_on_write(): void
    {
        $data = ['current_stage' => 'Not Qualified'];
        $this->sync->syncWritableAttributes($data);
        $this->assertSame(Lead::STATUS_LOST, $data['status']);
    }

    public function test_legacy_school_segment_maps_to_education_and_private_school_org(): void
    {
        $data = ['customer_segment' => 'School'];
        $this->sync->backfillCustomerSegmentLabels($data);
        $this->assertSame('Education', $data['customer_segment']);
        $this->assertSame('Private School', $data['organization_type']);
    }

    public function test_legacy_school_does_not_overwrite_existing_organization_type(): void
    {
        $data = [
            'customer_segment' => 'School',
            'organization_type' => 'International School',
        ];
        $this->sync->backfillCustomerSegmentLabels($data);
        $this->assertSame('Education', $data['customer_segment']);
        $this->assertSame('International School', $data['organization_type']);
    }

    public function test_org_type_label_in_segment_backfills_organization_type(): void
    {
        $data = ['customer_segment' => 'Private School'];
        $this->sync->backfillCustomerSegmentLabels($data);
        $this->sync->backfillOrganizationType($data);
        $this->assertSame('Private School', $data['organization_type']);
        $this->assertSame('Private School', $data['customer_segment']);
    }

    public function test_education_segment_does_not_set_organization_type(): void
    {
        $data = ['customer_segment' => 'Education'];
        $this->sync->backfillOrganizationType($data);
        $this->assertArrayNotHasKey('organization_type', $data);
    }
}
