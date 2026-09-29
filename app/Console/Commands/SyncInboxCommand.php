<?php

namespace App\Console\Commands;

use App\Domain\Inbox\ConversationIngestService;
use Illuminate\Console\Command;

class SyncInboxCommand extends Command
{
    protected $signature = 'inbox:sync {--messages : Pull message threads for each conversation}';

    protected $description = 'Pull conversations (and optionally messages) from the ConvyMes gateway into the CRM inbox';

    public function handle(ConversationIngestService $ingest): int
    {
        $withMessages = $this->option('messages');

        $this->info('Syncing inbox from ConvyMes' . ($withMessages ? ' (with messages)' : '') . '…');

        try {
            $stats = $ingest->syncFromGateway($withMessages);
        } catch (\Throwable $e) {
            $this->error('Sync failed: ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->table(
            ['Metric', 'Count'],
            [
                ['Conversations ingested', $stats['conversations']],
                ['Messages ingested', $stats['messages']],
                ['Messages skipped (dedupe)', $stats['skipped']],
            ]
        );

        return self::SUCCESS;
    }
}
