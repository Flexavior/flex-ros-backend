<?php

namespace App\Domain\Crm;

use App\Events\CrmDashboardUpdated;

class CrmDashboardBroadcastService
{
    public function notify(string $reason = 'crm'): void
    {
        if (! $this->enabled()) {
            return;
        }

        broadcast(new CrmDashboardUpdated($reason));
    }

    protected function enabled(): bool
    {
        $driver = config('broadcasting.default');

        return in_array($driver, ['reverb', 'pusher', 'log'], true);
    }
}
