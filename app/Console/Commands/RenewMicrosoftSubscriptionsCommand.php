<?php

namespace App\Console\Commands;

use App\Domain\Microsoft\GraphSubscriptionService;
use Illuminate\Console\Command;

class RenewMicrosoftSubscriptionsCommand extends Command
{
    protected $signature = 'microsoft:renew-subscriptions';

    protected $description = 'Renew Microsoft Graph mail inbox subscriptions before they expire';

    public function handle(GraphSubscriptionService $subscriptions): int
    {
        $count = $subscriptions->renewAll();
        $this->info("Renewed {$count} subscription(s).");

        return self::SUCCESS;
    }
}
