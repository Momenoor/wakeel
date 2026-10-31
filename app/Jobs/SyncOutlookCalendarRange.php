<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\MMS\Calendar\OutlookCalendarSync;
use Carbon\CarbonImmutable;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * "Sync with Outlook" between two dates, on the queue — reading months of Outlook events took five to fourteen
 * seconds in the page — whoever asked told in the bell what came of it.
 */
class SyncOutlookCalendarRange implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public CarbonImmutable $from, public CarbonImmutable $to, public ?int $userId = null) {}

    public function handle(OutlookCalendarSync $sync): void
    {
        try {
            $counts = $sync->sync(Carbon::instance($this->from)->startOfDay(), Carbon::instance($this->to)->endOfDay());
            $notification = Notification::make()->success()
                ->title(__('Outlook sync done'))
                ->body(__(':added added, :updated updated, :removed removed, :linked matter link(s).', $counts));
        } catch (Throwable $e) {
            Log::warning('Outlook calendar not synced: '.$e->getMessage());
            $notification = Notification::make()->danger()->title(__('Outlook sync failed'))->body($e->getMessage());
        }

        if ($this->userId && ($user = User::find($this->userId))) {
            $notification->sendToDatabase($user);
        }
    }
}
