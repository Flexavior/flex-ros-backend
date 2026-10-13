<?php

namespace App\Domain\Crm;

use App\Models\Lead;
use Illuminate\Support\Str;

/**
 * Normalizes lead pipeline + source fields on read/write.
 * All inbound/outbound shaping for canonical vs legacy columns lives here.
 */
class LeadPipelineSyncService
{
    /** @var array<string, string> picklist label (lower) → legacy status slug */
    private const STAGE_TO_STATUS = [
        'new' => Lead::STATUS_NEW,
        'contacted' => Lead::STATUS_CONTACTED,
        'qualified' => Lead::STATUS_QUALIFIED,
        'demo / meeting' => Lead::STATUS_APPOINTMENT,
        'proposal' => Lead::STATUS_QUALIFIED,
        'proposal sent' => Lead::STATUS_QUALIFIED,
        'negotiation' => Lead::STATUS_QUALIFIED,
        'won' => Lead::STATUS_CONVERTED,
        'lost' => Lead::STATUS_LOST,
        'not qualified' => Lead::STATUS_LOST,
    ];

    /** @var array<string, string> legacy status slug → default picklist label when stage empty */
    private const STATUS_TO_STAGE = [
        Lead::STATUS_NEW => 'New',
        Lead::STATUS_CONTACTED => 'Contacted',
        Lead::STATUS_QUALIFIED => 'Qualified',
        Lead::STATUS_APPOINTMENT => 'Demo / Meeting',
        Lead::STATUS_CONVERTED => 'Won',
        Lead::STATUS_LOST => 'Lost',
    ];

    public function resolveStageLabel(?string $currentStage, ?string $status): string
    {
        $stage = trim((string) ($currentStage ?? ''));
        if ($stage !== '') {
            return $stage;
        }

        $slug = trim((string) ($status ?? ''));

        return self::STATUS_TO_STAGE[$slug] ?? ($slug !== '' ? ucfirst($slug) : 'New');
    }

    /**
     * Merge legacy import columns into canonical attributes before persist.
     * Keeps DB `source` / `status` mirrors in sync for reimported SQL rows.
     *
     * @param  array<string, mixed>  $data
     */
    public function syncWritableAttributes(array &$data): void
    {
        $this->syncStageWritable($data);
        $this->syncSourceWritable($data);
        unset($data['stage_id']);
    }

    /**
     * Map obsolete segment labels to current Settings catalogue (e.g. School → Education size band).
     */
    public function backfillCustomerSegmentLabels(array &$attributes): void
    {
        $segment = trim((string) ($attributes[LeadFieldCanonical::CUSTOMER_SEGMENT] ?? ''));
        if ($segment === '') {
            return;
        }
        if (strcasecmp($segment, 'School') === 0) {
            $attributes[LeadFieldCanonical::CUSTOMER_SEGMENT] = 'Education';
            if (empty($attributes[LeadFieldCanonical::ORGANIZATION_TYPE])) {
                $attributes[LeadFieldCanonical::ORGANIZATION_TYPE] = 'Private School';
            }
        }
    }

    /**
     * Backfill organization_type from customer_segment when segment mistakenly holds an org-type label (SQL reimport).
     * Does not treat segment band "Education" as organization type — use organization_type for Private School, etc.
     */
    public function backfillOrganizationType(array &$attributes): void
    {
        if (!empty($attributes[LeadFieldCanonical::ORGANIZATION_TYPE])) {
            return;
        }
        $segment = trim((string) ($attributes[LeadFieldCanonical::CUSTOMER_SEGMENT] ?? ''));
        if ($segment === '') {
            return;
        }
        $allowed = array_flip(LeadPicklistService::DEFAULT_ORGANIZATION_TYPES);
        if (isset($allowed[$segment])) {
            $attributes[LeadFieldCanonical::ORGANIZATION_TYPE] = $segment;
        }
    }

    public function resolveLeadSource(?string $leadSource, ?string $source): ?string
    {
        $primary = trim((string) ($leadSource ?? ''));
        if ($primary !== '') {
            return $primary;
        }
        $legacy = trim((string) ($source ?? ''));

        return $legacy !== '' ? $legacy : null;
    }

    /** @param  array<string, mixed>  $payload */
    public function enrichApiPayload(array $payload): array
    {
        $payload[LeadFieldCanonical::PIPELINE_STAGE] = $this->resolveStageLabel(
            $payload[LeadFieldCanonical::PIPELINE_STAGE] ?? null,
            $payload[LeadFieldCanonical::LEGACY_STATUS] ?? null
        );
        $resolvedSource = $this->resolveLeadSource(
            $payload[LeadFieldCanonical::LEAD_SOURCE] ?? null,
            $payload[LeadFieldCanonical::LEGACY_SOURCE] ?? null
        );
        if ($resolvedSource) {
            $payload[LeadFieldCanonical::LEAD_SOURCE] = $resolvedSource;
        }

        foreach (LeadFieldCanonical::STRIP_FROM_API as $key) {
            unset($payload[$key]);
        }

        return $payload;
    }

    public function enrichLead(Lead $lead): Lead
    {
        $lead->setAttribute(
            LeadFieldCanonical::PIPELINE_STAGE,
            $this->resolveStageLabel($lead->current_stage, $lead->status)
        );
        $resolvedSource = $this->resolveLeadSource($lead->lead_source, $lead->source);
        if ($resolvedSource) {
            $lead->setAttribute(LeadFieldCanonical::LEAD_SOURCE, $resolvedSource);
            // In-memory mirror for list serialization; API layer strips legacy key from JSON.
            $lead->setAttribute(LeadFieldCanonical::LEGACY_SOURCE, $resolvedSource);
        }

        return $lead;
    }

    /** @param  array<string, mixed>  $data */
    private function syncStageWritable(array &$data): void
    {
        if (!empty($data[LeadFieldCanonical::PIPELINE_STAGE]) && empty($data[LeadFieldCanonical::LEGACY_STATUS])) {
            $status = self::STAGE_TO_STATUS[Str::lower(trim((string) $data[LeadFieldCanonical::PIPELINE_STAGE]))] ?? null;
            if ($status) {
                $data[LeadFieldCanonical::LEGACY_STATUS] = $status;
            }
        }

        if (!empty($data[LeadFieldCanonical::LEGACY_STATUS]) && empty($data[LeadFieldCanonical::PIPELINE_STAGE])) {
            $data[LeadFieldCanonical::PIPELINE_STAGE] = self::STATUS_TO_STAGE[$data[LeadFieldCanonical::LEGACY_STATUS]] ?? null;
        }
    }

    /** @param  array<string, mixed>  $data */
    private function syncSourceWritable(array &$data): void
    {
        if (!empty($data[LeadFieldCanonical::LEGACY_SOURCE]) && empty($data[LeadFieldCanonical::LEAD_SOURCE])) {
            $data[LeadFieldCanonical::LEAD_SOURCE] = $data[LeadFieldCanonical::LEGACY_SOURCE];
        }
        if (!empty($data[LeadFieldCanonical::LEAD_SOURCE])) {
            $data[LeadFieldCanonical::LEGACY_SOURCE] = $data[LeadFieldCanonical::LEAD_SOURCE];
        }
    }
}
