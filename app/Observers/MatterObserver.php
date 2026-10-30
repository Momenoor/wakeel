<?php

namespace App\Observers;

use App\Enums\MatterCollectionStatus;
use App\Jobs\MakeMatterPhaseFolders;
use App\Models\Matter;
use App\Services\MMS\FeeChangeNotifier;
use App\Services\MMS\MatterOneDriveFolders;
use App\Services\MMS\MatterProgressRecorder;
use App\Services\MMS\NewMatterNotification;
use Illuminate\Support\Facades\Log;

class MatterObserver
{
    public function creating(Matter $matter): void
    {
        $matter->collection_status ??= MatterCollectionStatus::NO_FEES;
    }

    public function created(Matter $matter): void
    {

        $matterId = $matter->id;

        if (! $matterId) {
            Log::error('MatterObserver@created: matter has no ID at observer time.');

            return;
        }

        dispatch(function () use ($matterId) {
            try {
                $matter = Matter::withTrashed()->find($matterId);

                if (! $matter) {
                    Log::error("NewMatterNotification [created]: Matter #{$matterId} not found in DB.");

                    return;
                }

                if (! $matter->distributed_at) {
                    Log::info("NewMatterNotification [created]: Matter #{$matterId} has no distributed_at, skipping.");

                    return;
                }

                app(NewMatterNotification::class)->sendToAssistants($matter);

            } catch (\Throwable $e) {
                Log::error("NewMatterNotification [created]: Failed for Matter #{$matterId}.", [
                    'error' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]);
            }
        })->afterCommit();
    }

    public function saved(Matter $matter): void
    {
        $matter->updateCollectionStatus();

        // The reports' dates, as steps in its progress.
        if ($matter->wasChanged(['initial_report_at', 'final_report_at'])
            || ($matter->wasRecentlyCreated && ($matter->initial_report_at || $matter->final_report_at))) {
            MatterProgressRecorder::reports($matter);
        }

        // Finished: its OneDrive folder goes to the assistant's folder for finished matters.
        if ($matter->final_report_at !== null && $matter->wasChanged('final_report_at')) {
            MatterOneDriveFolders::queueMoveToFinished($matter);
        }

        // Its phase changed: the new phase's folders made in its OneDrive folders.
        if (! $matter->wasRecentlyCreated && filled($matter->phase) && $matter->wasChanged('phase')) {
            MakeMatterPhaseFolders::dispatch($matter->getKey())->afterCommit();
        }

    }

    public function deleted(Matter $matter): void
    {
        $matter->children()->delete();
    }

    public function restored(Matter $matter): void
    {
        $matter->children()->onlyTrashed()->restore();
    }

    public function forceDeleting(Matter $matter): void
    {
        $matter->children()->withTrashed()->each(
            fn (Matter $child) => $child->forceDelete()
        );
        $matter->matterParties()->delete();
        // Gone with the matter: no one told of each fee.
        FeeChangeNotifier::quietly(fn () => $matter->fees()->each(function ($fee) {
            $fee->allocations()->delete();
            $fee->delete();
        }));
        $matter->allocations()->delete();
        $matter->notes()->delete();

        // Every file, those already hidden too: deleted with its file, financial evidence only hidden (kept, of
        // no matter once the matter is gone).
        // A loop, not each->delete(): deleting a file for good answers false, which would stop each() there.
        foreach ($matter->attachments()->withTrashed()->get() as $attachment) {
            // Evidence hidden before stays as it was hidden (hiding it again would move when that was).
            if (! ($attachment->trashed() && $attachment->category?->keepsEvidence())) {
                $attachment->delete();
            }
        }
        $matter->requests()->delete();
    }
}
