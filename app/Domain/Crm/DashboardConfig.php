<?php

namespace App\Domain\Crm;

use App\Models\Setting;

final class DashboardConfig
{
    public const MQL_STAGES_KEY = 'crm.dashboard.mql_stages';
    public const SQL_STAGES_KEY = 'crm.dashboard.sql_stages';
    public const PIPELINE_STAGES_KEY = 'crm.dashboard.pipeline_stages';

    public const DEFAULT_MQL_STAGES = ['New', 'Contacted'];
    public const DEFAULT_SQL_STAGES = ['Qualified', 'Demo / Meeting', 'Proposal Sent', 'Negotiation'];
    public const DEFAULT_PIPELINE_STAGES = ['Proposal Sent', 'Negotiation'];

    /** @return array<string, list<string>> */
    public static function all(): array
    {
        return [
            self::MQL_STAGES_KEY => self::stages(self::MQL_STAGES_KEY, self::DEFAULT_MQL_STAGES),
            self::SQL_STAGES_KEY => self::stages(self::SQL_STAGES_KEY, self::DEFAULT_SQL_STAGES),
            self::PIPELINE_STAGES_KEY => self::stages(self::PIPELINE_STAGES_KEY, self::DEFAULT_PIPELINE_STAGES),
        ];
    }

    /** @param list<string> $default
     *  @return list<string>
     */
    public static function stages(string $key, array $default): array
    {
        $stored = Setting::get($key, $default);
        if (! is_array($stored)) {
            return $default;
        }

        $clean = array_values(array_unique(array_filter(
            array_map(static fn ($stage) => trim((string) $stage), $stored),
            static fn (string $stage) => $stage !== ''
        )));

        return $clean !== [] ? $clean : $default;
    }
}
