<?php

namespace App\Domain\Inbox;

use App\Events\InboxUpdated;

class InboxBroadcastService
{
    public function notify(int $conversationId, string $reason = 'message'): void
    {
        if (!$this->enabled()) {
            return;
        }

        broadcast(new InboxUpdated($conversationId, $reason));
    }

    /** Signal all inbox clients to refresh lists (e.g. after bulk sync). */
    public function notifySyncComplete(): void
    {
        $this->notify(0, 'sync');
    }

    protected function enabled(): bool
    {
        $driver = config('broadcasting.default');

        return in_array($driver, ['reverb', 'pusher', 'log'], true);
    }
}
