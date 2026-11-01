<?php

namespace App\Jobs;

use App\Models\Party;
use App\Models\User;
use App\Services\MMS\MatterOneDriveFolders;
use App\Services\MMS\MatterReplyCollector;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * An assistant's two folders — the matters in progress and the finished — given the standard names (Settings →
 * OneDrive Folders → "Apply to the assistants"): what they hold moved under the standard names at the top of
 * their OneDrive, their profile's paths set, the matters' links refreshed (MatterOneDriveFolders::standardiseRoots()).
 * On the queue, one assistant a job: moving a folder of many matters takes a while; whoever asked is told.
 */
class StandardiseAssistantFolders implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public int $partyId, public ?int $userId) {}

    public function handle(MatterOneDriveFolders $folders): void
    {
        $party = Party::find($this->partyId);

        if ($party === null || blank($party->onedrive_email)) {
            return;
        }

        try {
            $done = $folders->standardiseRoots($party);
            $points = array_values(array_filter([
                $done['matters'] !== null ? __('Matters in progress: :folder', ['folder' => $done['matters']]) : null,
                $done['finished'] !== null ? __('Finished matters: :folder', ['folder' => $done['finished']]) : null,
                $done['links'] ? trans_choice(':count link updated.|:count links updated.', $done['links'], ['count' => $done['links']]) : null,
            ]));
            $notification = Notification::make()
                ->success()
                ->title(__('OneDrive folders of :assistant given the standard names', ['assistant' => $party->name]))
                ->body($points !== [] ? MatterReplyCollector::bullets($points) : __('They had them already.'));
        } catch (Throwable $e) {
            Log::warning('Assistant folders not standardised: '.$e->getMessage(), ['party' => $this->partyId]);
            $notification = Notification::make()
                ->danger()
                ->title(__('OneDrive folders of :assistant could not be given the standard names', ['assistant' => $party->name]))
                ->body($e->getMessage());
        }

        if ($this->userId && $user = User::find($this->userId)) {
            $notification->sendToDatabase($user);
        }
    }
}
