<?php

namespace Database\Seeders;

use App\Models\MarketingChannel;
use Illuminate\Database\Seeder;

/** Idempotent marketing channel catalogue (direct/offline + digital). Safe for Docker bootstrap. */
class MarketingChannelSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->catalogue() as $ch) {
            MarketingChannel::updateOrCreate(
                ['code' => $ch['code']],
                $ch + ['is_active' => true]
            );
        }
    }

    /** @return list<array{name: string, code: string, type: string}> */
    public function catalogue(): array
    {
        return [
            ['name' => 'Event', 'code' => 'event', 'type' => 'offline'],
            ['name' => 'Exhibition & Fair', 'code' => 'exhibition_fair', 'type' => 'offline'],
            ['name' => 'Phone', 'code' => 'phone', 'type' => 'messaging'],
            ['name' => 'Facebook', 'code' => 'facebook', 'type' => 'social'],
            ['name' => 'TikTok', 'code' => 'tiktok', 'type' => 'social'],
            ['name' => 'LinkedIn', 'code' => 'linkedin', 'type' => 'social'],
            ['name' => 'Telegram', 'code' => 'telegram', 'type' => 'messaging'],
            ['name' => 'Viber', 'code' => 'viber', 'type' => 'messaging'],
            ['name' => 'Google Search', 'code' => 'google_search', 'type' => 'search'],
            ['name' => 'Website / SEO', 'code' => 'website', 'type' => 'other'],
            ['name' => 'Email Newsletter', 'code' => 'email', 'type' => 'email'],
        ];
    }
}
