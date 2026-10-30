<?php

namespace App\Console\Commands;

use App\Services\MMS\OneDriveAddedEmails;
use Illuminate\Console\Command;

/**
 * The emails put in the matters' OneDrive folders by hand (.eml, .msg), read and added to their matters — every
 * half hour, by the scheduler; the folder read longest ago first, until the time is up.
 */
class ReadOneDriveAddedEmails extends Command
{
    protected $signature = 'onedrive:read-added-emails {--max-seconds=50 : stop starting new folders after this long}';

    protected $description = 'Read the emails put in the matters\' OneDrive folders by hand and add them to their matters';

    public function handle(OneDriveAddedEmails $emails): int
    {
        $result = $emails->readDue((int) $this->option('max-seconds') ?: null);

        $this->info(__(':added emails added from :folders folders.', ['added' => $result['added'], 'folders' => $result['folders']]));

        foreach ($result['errors'] as $error) {
            $this->warn($error);
        }

        return self::SUCCESS;
    }
}
