<?php

namespace App\Domain\Crm;

use App\Models\Customer;
use App\Models\Setting;

class ClientIdGenerator
{
    /**
     * Generate the next sequential Client ID for the current year.
     * Format: {PREFIX}-YYYY-####, e.g. CUS-2026-0001.
     */
    public function generate(): string
    {
        $prefix = Setting::get('crm.client_id_prefix', 'CUS');
        $year = now()->year;

        $next = (int) Customer::where('client_id', 'like', "{$prefix}-{$year}-%")
            ->selectRaw('MAX(CAST(SUBSTRING_INDEX(client_id, "-", -1) AS UNSIGNED)) as max_seq')
            ->value('max_seq') + 1;

        return sprintf('%s-%d-%04d', $prefix, $year, $next);
    }
}
