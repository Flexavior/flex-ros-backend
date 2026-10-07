<?php

namespace App\Domain\Microsoft;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Internal-only Teams alerts (incoming webhook). No customer Teams channel in inbox.
 */
class InternalTeamsNotifier
{
    public function notify(string $title, string $text, ?string $url = null): void
    {
        $webhook = config('microsoft.teams_webhook_url');
        if (!$webhook) {
            return;
        }

        $facts = [['name' => 'Details', 'value' => $text]];
        if ($url) {
            $facts[] = ['name' => 'Link', 'value' => $url];
        }

        try {
            Http::timeout(10)->post($webhook, [
                '@type' => 'MessageCard',
                '@context' => 'https://schema.org/extensions',
                'summary' => $title,
                'themeColor' => '2456E6',
                'title' => $title,
                'sections' => [['facts' => $facts]],
            ]);
        } catch (\Throwable $e) {
            Log::warning('Teams webhook failed: '.$e->getMessage());
        }
    }
}
