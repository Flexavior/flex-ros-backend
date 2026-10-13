<?php

namespace App\Domain\Crm;

use App\Models\Lead;
use Illuminate\Support\Str;

/**
 * Single place for lead pipeline + source field sync.
 * Canonical UX fields: current_stage, lead_source.
 * Legacy mirrors (status, source) kept for queries/dashboard until fully migrated.
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
    ];

    /** @var array<string, string> legacy status slug → default picklist label */
    private const STATUS_TO_STAGE = [
        Lead::STATUS_NEW => 'New',
        Lead::STATUS_CONTACTED => 'Contacted',
        Lead::STATUS_QUALIFIED => 'Qualified',
        Lead::STATUS_APPOINTMENT => 'Demo / Meeting',
        Lead::STATUS_CONVERTED => 'Won',
        Lead::STATUS_LOST => 'Lost',
    ];

    /** Normalize inbound create/update attributes (mutates array). */
    public function syncWritableAttributes(array &$data): void
    {
        if (!empty($data['current_stage']) && empty($data['status'])) {
            $status = self::STAGE_TO_STATUS[Str::lower(trim((string) $data['current_stage']))] ?? null;
            if ($status) {
                $data['status'] = $status;
            }
        }

        if (!empty($data['status']) && empty($data['current_stage'])) {
            $data['current_stage'] = self::STATUS_TO_STAGE[$data['status']] ?? null;
        }

        if (!empty($data['source']) && empty($data['lead_source'])) {
            $data['lead_source'] = $data['source'];
        }
        if (!empty($data['lead_source'])) {
            $data['source'] = $data['lead_source'];
        }

        unset($data['stage_id']);
    }

    public function resolveStageLabel(?string $currentStage, ?string $status): string
    {
        $stage = trim((string) ($currentStage ?? ''));
        if ($stage !== '') {
            return $stage;
        }

        $slug = trim((string) ($status ?? ''));

        return self::STATUS_TO_STAGE[$slug] ?? ($slug !== '' ? ucfirst($slug) : 'New');
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
        $payload['current_stage'] = $this->resolveStageLabel(
            $payload['current_stage'] ?? null,
            $payload['status'] ?? null
        );
        $payload['lead_source'] = $this->resolveLeadSource(
            $payload['lead_source'] ?? null,
            $payload['source'] ?? null
        );
        if ($payload['lead_source']) {
            $payload['source'] = $payload['lead_source'];
        }

        unset($payload['stage_id']);

        return $payload;
    }

    public function enrichLead(Lead $lead): Lead
    {
        $lead->setAttribute(
            'current_stage',
            $this->resolveStageLabel($lead->current_stage, $lead->status)
        );
        $resolvedSource = $this->resolveLeadSource($lead->lead_source, $lead->source);
        if ($resolvedSource) {
            $lead->setAttribute('lead_source', $resolvedSource);
            $lead->setAttribute('source', $resolvedSource);
        }

        return $lead;
    }
}
