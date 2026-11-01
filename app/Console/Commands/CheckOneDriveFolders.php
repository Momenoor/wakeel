<?php

namespace App\Console\Commands;

use App\Services\MMS\MatterOneDriveFolders;
use App\Services\MMS\OneDriveFolderCheck;
use Illuminate\Console\Command;

/**
 * The folders the system made in the matters' OneDrive folders looked for (OneDriveFolderCheck): one renamed or
 * moved by hand told to the matter's people. Scheduled once a day; run by hand to check now.
 */
class CheckOneDriveFolders extends Command
{
    protected $signature = 'onedrive:check-folders {--max-seconds= : Stop starting new matters after this many seconds}';

    protected $description = 'Looks for the folders the system made in the matters\' OneDrive folders and tells of those missing';

    public function handle(OneDriveFolderCheck $check): int
    {
        if (! MatterOneDriveFolders::enabled()) {
            $this->info('OneDrive folders are off.');

            return self::SUCCESS;
        }

        $seconds = $this->option('max-seconds');
        $result = $check->run($seconds !== null ? (int) $seconds : null);

        $this->info("Checked {$result['checked']} matters; {$result['missing']} with folders missing.".($result['stopped'] ? ' Stopped for time.' : ''));

        foreach ($result['errors'] as $error) {
            $this->warn($error);
        }

        return $result['errors'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
