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

    public const LIST_PER_PAGE_DEFAULT = 25;

    public const LIST_PER_PAGE_MAX = 50;

    public const LIST_PAGE_MAX = 500;

    public const TEXT_FIELD_MAX = 65535;

    public const NOTES_MAX = 5000;

    public const ENGAGEMENT_SUMMARY_MAX = 2000;

    private function __construct()
    {
    }
}
