<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Signals operational CRM clients to refetch dashboard metrics (Reverb/Echo).
 */
class CrmDashboardUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $reason = 'crm',
    ) {
    }

    /** @return array<int, \Illuminate\Broadcasting\Channel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('crm-dashboard')];
    }

    public function broadcastAs(): string
    {
        return 'updated';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'reason' => $this->reason,
        ];
    }
}
