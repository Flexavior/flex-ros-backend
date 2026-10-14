<?php

namespace App\Support;

use App\Models\SystemNotification;

final class SystemNotificationCatalog
{
    /** @return list<array{id: int, body: string, effective_until: string|null}> */
    public static function activeSummaries(): array
    {
        return SystemNotification::query()
            ->active()
            ->orderByDesc('created_at')
            ->get(['id', 'body', 'effective_until'])
            ->map(static fn (SystemNotification $n) => [
                'id' => $n->id,
                'body' => $n->body,
                'effective_until' => $n->effective_until?->toIso8601String(),
            ])
            ->values()
            ->all();
    }
}
