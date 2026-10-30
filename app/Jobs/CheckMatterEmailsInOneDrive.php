<?php

namespace App\Jobs;

use App\Models\Matter;
use App\Models\MatterEmail;
use App\Models\User;
use App\Services\MMS\MatterOneDriveFolders;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * What is in OneDrive of a matter's received emails (their PDFs, originals and attachments), read on the queue —
 * one call to Microsoft per folder, up to half a minute for a matter with many — whoever asked told in the bell.
 */
class CheckMatterEmailsInOneDrive implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $matterId, public ?int $userId = null) {}

    public function handle(MatterOneDriveFolders $folders): void
    {
        $matter = Matter::find($this->matterId);

        if ($matter === null) {
            return;
        }

        $emails = $matter->emails()
            ->where('direction', MatterEmail::RECEIVED)
            ->where(fn ($q) => $q->where('status', MatterEmail::FILED)->orWhereNull('status'))
            ->get()
            ->all();
        $label = $matter->year.'/'.$matter->number;

        try {
            $notification = $folders->checkEmails($matter, $emails)
                ? Notification::make()->success()->title(__('OneDrive checked'))->body(__('Matter :matter — :count emails.', ['matter' => $label, 'count' => count($emails)]))
                : Notification::make()->warning()->title(__('Nothing to check'))->body(__('This matter has no OneDrive folder, or no folder is set for received emails.'));
        } catch (Throwable $e) {
            Log::warning('OneDrive not checked for matter '.$this->matterId.': '.$e->getMessage());
            $notification = Notification::make()->danger()->title(__('OneDrive could not be read'))->body($label.': '.$e->getMessage());
        }

        if ($this->userId && ($user = User::find($this->userId))) {
            $notification->sendToDatabase($user);
        }
    }
}
