<?php

namespace App\Console\Commands;

use App\Services\MMS\MatterReplyCollector;
use Illuminate\Console\Command;

/**
 * Replies to the matters' emails, collected from the sending mailboxes'
 * inboxes (MatterReplyCollector) — every ten minutes, by the scheduler.
 */
class CollectMatterReplies extends Command
{
    protected $signature = 'mail:collect-replies {--matter= : Only this matter\'s emails, read from its first one}';

    protected $description = 'Collect replies to emails sent from matters, keeping them and their files with the matter and in OneDrive';

    public function handle(MatterReplyCollector $collector): int
    {
        $result = $collector->collect($this->option('matter') ? (int) $this->option('matter') : null);

        $this->info(__(':count replies kept.', ['count' => $result['replies']]));

        foreach ($result['errors'] as $error) {
            $this->warn($error);
        }

        return self::SUCCESS;
    }
}
