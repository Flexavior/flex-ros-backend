<?php

namespace App\Domain\Crm;

/**
 * Shared limits for settings-backed catalogues and list APIs (N users / N rows).
 */
final class CrmConfigLimits
{
    public const PICKLIST_OPTION_MAX_LENGTH = 120;

    /** Max options per picklist key (prevents bloated settings JSON / Rule::in abuse). */
    public const PICKLIST_OPTIONS_MAX_COUNT = 200;

    /** Minimum options required when admin saves critical catalogues (poka-yoke). */
    public const PICKLIST_MIN_OPTIONS_CRITICAL = 3;

    public const LIST_PER_PAGE_DEFAULT = 25;

    public const LIST_PER_PAGE_MAX = 50;

    public const LIST_PAGE_MAX = 500;

    public const TEXT_FIELD_MAX = 65535;

    public const NOTES_MAX = 5000;

    public const ENGAGEMENT_SUMMARY_MAX = 2000;

    public const LEAD_CONTACT_NAME_MAX = 150;

    public const LEAD_CONTACT_ROLE_MAX = 120;

    public const LEAD_CONTACT_PHONE_MAX = 50;

    public const LEAD_CONTACT_VIBER_ID_MAX = 120;

    public const CUSTOMER_ADDRESS_MAX = 5000;

    /** Document library upload (kilobytes). */
    public const DOCUMENT_UPLOAD_MAX_KB = 10240;

    private function __construct()
    {
    }
}
