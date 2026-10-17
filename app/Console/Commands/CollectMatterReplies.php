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
    protected $signature = 'mail:collect-replies {--matter= : Only this matter\'s emails, read from its first one} {--max-seconds= : Stop after this many seconds and go on from there next time}';

    protected $description = 'Collect replies to emails sent from matters, and other emails from their parties, keeping them and their files with the matter and in OneDrive';

    public function handle(MatterReplyCollector $collector): int
    {
        $result = $collector->collect(
            $this->option('matter') ? (int) $this->option('matter') : null,
            $this->option('max-seconds') !== null ? max(1, (int) $this->option('max-seconds')) : null,
        );

        $this->info(__(':count replies kept.', ['count' => $result['replies']]));
        $this->info(__(':count other emails from the parties kept, :unfiled waiting for their matter.', ['count' => $result['emails'], 'unfiled' => $result['unfiled']]));

        foreach ($result['unlinked'] as $matter) {
            $this->warn(__('Matter :matter is not linked to a OneDrive folder: its emails were kept in the system only.', ['matter' => $matter['label']]));
        }

        if ($result['stopped']) {
            $this->info(__('Stopped for time; the rest is read on the next run.'));
        }

        foreach ($result['errors'] as $error) {
            $this->warn($error);
        }

        return $result['errors'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
