<?php

namespace App\Jobs;

use App\Filament\Mms\Resources\Matters\MatterResource;
use App\Models\Matter;
use App\Models\MatterEmail;
use App\Models\User;
use App\Services\MMS\MatterReplyCollector;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * An unfiled email filed on the matter a person chose (Unfiled emails → File to matter), on the queue: its
 * PDF and files go to the matter and its OneDrive, which can take a minute — a page held that long kept the
 * person waiting. While it runs the email shows as being filed and cannot be filed twice; whoever filed it is
 * told in their notifications when it is done.
 */
class FileUnfiledEmail implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    /** @param  bool  $tell  whether whoever filed it is told when done (not when done at once, on their page) */
    public function __construct(public int $emailId, public int $matterId, public ?int $userId = null, public bool $tell = true) {}

    /** Queued, unless it is already being filed. Whether it was. */
    public static function start(MatterEmail $email, Matter $matter, ?int $userId): bool
    {
        if (! Cache::add(self::filing($email->getKey()), $matter->getKey(), now()->addMinutes(15))) {
            return false;
        }

        self::dispatch((int) $email->getKey(), (int) $matter->getKey(), $userId, config('queue.default') !== 'sync');

        return true;
    }

    /** Whether it is being filed now. */
    public static function isFiling(int $emailId): bool
    {
        return Cache::has(self::filing($emailId));
    }

    public function handle(MatterReplyCollector $collector): void
    {
        try {
            $email = MatterEmail::find($this->emailId);
            $matter = Matter::find($this->matterId);

            // Filed or set aside meanwhile, or the matter gone: nothing to do.
            if (! $email || ! $matter || $email->status !== MatterEmail::PENDING) {
                return;
            }

            $collector->file($email, $matter, $this->userId);

            $this->tell(Notification::make()
                ->success()
                ->title(__('Filed on matter :matter.', ['matter' => $matter->year.'/'.$matter->number]))
                ->body($email->subject)
                ->actions([Action::make('view')->label(__('View'))->url(MatterResource::getUrl('view', ['record' => $matter], panel: 'mms'))]));
        } catch (Throwable $e) {
            Log::warning('Unfiled email not filed', ['email' => $this->emailId, 'matter' => $this->matterId, 'error' => $e->getMessage()]);

            // Told even when done at once: the page said it was being filed.
            $this->tell(Notification::make()
                ->danger()
                ->title(__('The email was not filed.'))
                ->body($e->getMessage()), always: true);
        } finally {
            Cache::forget(self::filing($this->emailId));
        }
    }

    private function tell(Notification $notification, bool $always = false): void
    {
        if (($this->tell || $always) && $user = $this->userId ? User::find($this->userId) : null) {
            $notification->sendToDatabase($user);
        }
    }

    private static function filing(int $emailId): string
    {
        return 'filing-email.'.$emailId;
    }
}
