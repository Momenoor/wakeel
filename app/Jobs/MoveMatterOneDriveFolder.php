<?php

namespace App\Jobs;

use App\Models\MatterOneDriveFolder;
use App\Services\MMS\MatterOneDriveFolders;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Jobs\SyncJob;
use Throwable;

/**
 * Moves a finished matter's folder to its assistant's folder for finished
 * matters, on the queue so finishing the matter never waits on Microsoft.
 * Retried a few times; after the last try the reason is kept on the folder.
 */
class MoveMatterOneDriveFolder implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public function __construct(public int $folderId) {}

    public function handle(MatterOneDriveFolders $folders): void
    {
        $folder = MatterOneDriveFolder::with('party')->find($this->folderId);

        if ($folder === null) {
            return;
        }

        try {
            $folders->moveToFinished($folder);
        } catch (Throwable $exception) {
            // Run straight away (QUEUE_CONNECTION=sync) there are no retries,
            // and throwing would fail finishing the matter itself.
            if ($this->job instanceof SyncJob || $this->job === null) {
                $this->failed($exception);

                return;
            }

            throw $exception;
        }
    }

    public function failed(Throwable $exception): void
    {
        MatterOneDriveFolder::whereKey($this->folderId)->update(['move_error' => mb_substr($exception->getMessage(), 0, 2000)]);
    }
}
