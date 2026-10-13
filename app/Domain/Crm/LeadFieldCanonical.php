<?php

namespace App\Domain\Crm;

/**
 * Canonical lead field names for API, UI, and new writes.
 *
 * | Canonical (use in app) | Legacy DB column | Role |
 * |------------------------|------------------|------|
 * | current_stage          | status           | Pipeline picklist label ↔ slug for stale/open scopes |
 * | lead_source            | source           | SQL imports may set `source` only; backfill normalizes |
 * | organization_type      | —                | Org category (Private School, …); not lead_source |
 * | customer_segment       | —                | Size band (e.g. Education, SME); not org type |
 *
 * Do not drop legacy columns: they support one-shot SQL reimports and historical reporting.
 */
final class LeadFieldCanonical
{
    public const PIPELINE_STAGE = 'current_stage';

    public const LEAD_SOURCE = 'lead_source';

    /** @deprecated Persistence mirror only — omit from JSON API responses. */
    public const LEGACY_SOURCE = 'source';

    public const LEGACY_STATUS = 'status';

    public const ORGANIZATION_TYPE = 'organization_type';

    public const CUSTOMER_SEGMENT = 'customer_segment';

    /** Keys removed from outbound API payloads (clients use canonical names). */
    public const STRIP_FROM_API = [
        self::LEGACY_SOURCE,
        'stage_id',
    ];
}
