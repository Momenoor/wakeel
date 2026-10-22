<?php

namespace App\Console\Commands;

use App\Services\Attachments\CopyToAzure;
use App\Support\Integrations;
use Illuminate\Console\Command;

/**
 * The files kept on this server copied to Azure Blob Storage, once it is set in Settings → Integrations
 * (also there, a few hundred at a time, as "Copy the files to Azure"):
 *
 *     php artisan attachments:to-azure                  (copies them; the originals are kept)
 *     php artisan attachments:to-azure --delete-local   (and deletes each original once its copy is checked)
 *
 * See App\Services\Attachments\CopyToAzure.
 */
class CopyAttachmentsToAzure extends Command
{
    protected $signature = 'attachments:to-azure
        {--limit= : Copy at most this many files in this run}
        {--delete-local : Delete each original once its copy in Azure is checked}';

    protected $description = 'Copy the attached files, signatures and stamps kept on this server to Azure Blob Storage';

    public function handle(CopyToAzure $copy): int
    {
        if (! Integrations::azureOn()) {
            $this->error('Azure Blob Storage is not set: Settings → Integrations.');

            return self::FAILURE;
        }

        $stats = $copy->run(
            $this->option('limit') !== null ? max(0, (int) $this->option('limit')) : null,
            (bool) $this->option('delete-local'),
        );

        foreach ($stats['errors'] as $error) {
            $this->error($error);
        }

        $this->table(
            ['Copied', 'Size', 'File missing (left as it is)', 'Failed', 'Still to copy'],
            [[$stats['copied'], number_format($stats['bytes'] / (1024 * 1024), 1).' MB', $stats['missing'], $stats['failed'], CopyToAzure::remaining()]],
        );

        return $stats['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
