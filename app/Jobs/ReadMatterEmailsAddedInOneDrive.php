<?php

namespace App\Jobs;

use App\Models\Matter;
use App\Models\User;
use App\Services\MMS\OneDriveAddedEmails;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The emails put in a matter's OneDrive folder by hand (.eml, .msg), read now on the queue — reading the folder
 * and each file took seconds in the page — whoever asked told in the bell how many were added, and why any
 * could not be.
 */
class ReadMatterEmailsAddedInOneDrive implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public int $matterId, public ?int $userId = null) {}

    public function handle(OneDriveAddedEmails $reader): void
    {
        $matter = Matter::find($this->matterId);

        if ($matter === null) {
            return;
        }

        $label = $matter->year.'/'.$matter->number;

        try {
            $result = $reader->readMatter($matter, $this->userId);
            $title = __(':count emails added', ['count' => $result['added']]);
            $notification = $result['errors'] !== []
                ? Notification::make()->warning()->title($title)->body($label.': '.implode("\n", $result['errors']))
                : Notification::make()->success()->title($title)->body(__('Matter :matter — :count emails.', ['matter' => $label, 'count' => $result['added']]));
        } catch (Throwable $e) {
            Log::warning('Emails added in OneDrive not read for matter '.$this->matterId.': '.$e->getMessage());
            $notification = Notification::make()->danger()->title(__('OneDrive could not be read'))->body($label.': '.$e->getMessage());
        }

        if ($this->userId && ($user = User::find($this->userId))) {
            $notification->sendToDatabase($user);
        }
    }
}
