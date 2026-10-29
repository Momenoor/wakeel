<?php

namespace App\Console\Commands;

use App\Services\MMS\MailboxFolderForwarder;
use Illuminate\Console\Command;

/**
 * The emails that came into the mailboxes' folders forwarded on, each folder to its own address
 * (Settings → Mailbox folders) — every five minutes, by the scheduler.
 */
class ForwardMailboxFolders extends Command
{
    protected $signature = 'mail:forward-folders';

    protected $description = 'Forward the new emails of each mailbox folder set to be forwarded to its own address';

    public function handle(MailboxFolderForwarder $forwarder): int
    {
        $this->info(__(':count emails forwarded.', ['count' => $forwarder->run()]));

        return self::SUCCESS;
    }
}
