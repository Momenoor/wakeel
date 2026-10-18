<?php

namespace App\Jobs;

use App\Models\MatterOneDriveFolder;
use App\Services\MMS\MatterOneDriveFolders;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Creates one matter folder in an assistant's OneDrive, on the queue so
 * saving the matter never waits on Microsoft. Retried a few times; after
 * the last try the reason is kept on the folder for the matter page.
 */
class CreateMatterOneDriveFolder implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public function __construct(public int $folderId) {}

    public function handle(MatterOneDriveFolders $folders): void
    {
        $folder = MatterOneDriveFolder::with('party')->find($this->folderId);

        if ($folder === null || $folder->isCreated()) {
            return;
        }

        // No OneDrive account on the assistant's profile: retrying won't
        // find one (each try was logged as an error). Kept on the folder for
        // the matter page, which offers a retry once the profile has one.
        if ($folder->party === null || blank($folder->party->onedrive_email)) {
            $reason = __('No OneDrive account on :name\'s profile.', ['name' => $folder->party?->name ?? '—']);
            Log::info('OneDrive folder not made: '.$reason, ['folder' => $folder->getKey()]);
            $this->failed(new RuntimeException($reason));

            return;
        }

        try {
            $folders->create($folder);
        } catch (Throwable $exception) {
            // Run straight away (QUEUE_CONNECTION=sync) there are no
            // retries, and throwing would fail the save of the matter
            // itself — record why and let the matter page offer a retry.
            if ($this->job instanceof SyncJob || $this->job === null) {
                $this->failed($exception);

                return;
            }

            throw $exception;
        }
    }

    public function failed(Throwable $exception): void
    {
        MatterOneDriveFolder::whereKey($this->folderId)->update([
            'status' => MatterOneDriveFolder::FAILED,
            'error' => mb_substr($exception->getMessage(), 0, 2000),
        ]);
    }
}
