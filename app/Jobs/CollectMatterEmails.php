<?php

namespace App\Jobs;

use App\Enums\NotificationType;
use App\Models\Matter;
use App\Models\User;
use App\Services\MMS\MatterReplyCollector;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\HtmlString;

/**
 * The mailboxes read now, not waiting for the scheduled round: one matter's (the Progress tab's "Check for
 * emails"), or all of them (Unfiled emails → "Check mail now") — on the queue: reading a mailbox can take a
 * minute, and a page request held that long kept the server's other requests waiting. One at a time for each
 * (asked for again while waiting, not queued twice). Whoever asked is told what came of it, in their notifications.
 */
class CollectMatterEmails implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** Read once: a check asked for again is a new job. */
    public int $tries = 1;

    public int $timeout = 300;

    /** All the mailboxes: stopped this long in, the rest read by the next round (as the scheduled one does). */
    private const ALL_SECONDS = 240;

    /** Not queued again while one is: for as long as one may run. */
    public int $uniqueFor = 300;

    /** @param  int|null  $matterId  null: every mailbox */
    public function __construct(public ?int $matterId = null, public ?int $userId = null) {}

    /**
     * Asked for from a page: queued, and the person told it is under way.
     */
    public static function start(?int $matterId = null): void
    {
        static::dispatch($matterId, auth()->id());

        Notification::make()
            ->title(__('Checking for emails'))
            ->body(__('This takes up to a minute or two; what comes of it appears in your notifications.'))
            ->info()
            ->send();
    }

    public function uniqueId(): string
    {
        return (string) ($this->matterId ?? 'all');
    }

    public function handle(MatterReplyCollector $collector): void
    {
        if ($this->matterId !== null && ! Matter::query()->whereKey($this->matterId)->exists()) {
            return;
        }

        $result = $collector->collect($this->matterId, $this->matterId === null ? self::ALL_SECONDS : null);
        $user = $this->userId ? User::find($this->userId) : null;

        if ($user) {
            self::notification($result)->sendToDatabase($user);
        }
    }

    /**
     * What came of it: the replies kept, then one point each — the other emails kept, the matters
     * with no OneDrive folder to keep them in (with the files to download), and what went wrong.
     *
     * @param  array<string, mixed>  $result
     */
    public static function notification(array $result): Notification
    {
        $points = [
            $result['emails'] ? trans_choice(':count other email from the parties kept.|:count other emails from the parties kept.', $result['emails'], ['count' => $result['emails']]) : null,
            ($result['unfiled'] ?? 0) ? trans_choice(':count email waiting for its matter in Unfiled emails.|:count emails waiting for their matter in Unfiled emails.', $result['unfiled'], ['count' => $result['unfiled']]) : null,
            ($result['stopped'] ?? false) ? __('Stopped for time; the rest is read on the next run.') : null,
            // No folder to find them in: the matter, and the emails to download and keep by hand.
            ...array_map(fn (array $matter): HtmlString => new HtmlString(
                e(__('Matter :matter is not linked to a OneDrive folder: its emails were kept in the system only.', ['matter' => $matter['label']]))
                .' <a href="'.e($matter['url']).'" style="text-decoration: underline;">'.e(__('Open the matter')).'</a>'
                .($matter['downloads'] !== []
                    ? '<div>'.e(__('Download to put in OneDrive by hand:')).'</div>'.MatterReplyCollector::bullets(array_map(
                        fn (array $file): HtmlString => new HtmlString('<a href="'.e($file['url']).'" target="_blank" download style="text-decoration: underline;">'.e($file['name']).'</a>'),
                        $matter['downloads'],
                    ))->toHtml()
                    : '')
            ), $result['unlinked']),
            ...$result['errors'],
        ];
        $warn = $result['errors'] !== [] || $result['unlinked'] !== [];

        return Notification::make()
            ->title(trans_choice('No new replies.|:count new reply kept.|:count new replies kept.', $result['replies'], ['count' => $result['replies']]))
            ->body(array_filter($points) !== [] ? MatterReplyCollector::bullets($points) : null)
            ->status($warn ? 'warning' : 'success')
            // About emails: in the bell's Emails tab.
            ->viewData([NotificationType::DATA_KEY => NotificationType::MATTER_EMAIL_RECEIVED->value]);
    }
}
