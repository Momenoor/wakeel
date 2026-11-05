<?php

namespace App\Services\MMS;

use App\Models\MatterEmail;
use App\Models\Setting;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Emails collected from the inboxes in the last hours, undone as if never
 * collected: their files gone from the system and from the matters' OneDrive
 * (to its recycle bin), their steps in the progress, the contacts learnt from
 * them, and the emails themselves — those that were unread in the mailbox
 * before made unread again (one not known stays as it is). Each mailbox is
 * then read again from that time, so the next check collects them anew.
 */
class CollectedEmailUndo
{
    public function __construct(
        private readonly MatterReplyCollector $collector,
        private readonly MatterEmailDeletion $deletion,
    ) {}

    /**
     * The emails collected since then.
     *
     * @return Builder<MatterEmail>
     */
    public static function since(int $hours): Builder
    {
        return MatterEmail::query()
            ->where('direction', MatterEmail::RECEIVED)
            ->where('created_at', '>=', now()->subHours($hours));
    }

    /**
     * @return array{undone: int, unread: int, errors: list<string>}
     */
    public function undo(int $hours): array
    {
        $from = now()->subHours($hours);
        $result = ['undone' => 0, 'unread' => 0, 'errors' => []];
        $mailboxes = [];

        foreach (self::since($hours)->with('matter')->get() as $email) {
            try {
                $this->undoOne($email, $result);
                $key = (string) $email->sender_key;
                // Read again from when the oldest of them arrived — an email collected in these hours may
                // have come in days before (a backlog caught up).
                $arrived = $email->at && $email->at->lt($from) ? $email->at : $from;
                $mailboxes[$key] = isset($mailboxes[$key]) ? $mailboxes[$key]->min($arrived) : $arrived->copy();
                $result['undone']++;
            } catch (Throwable $e) {
                Log::warning('Collected email not undone: '.$e->getMessage(), ['email' => $email->getKey()]);
                $result['errors'][] = $email->subject.': '.$e->getMessage();
            }
        }

        // Each mailbox read again from then — not from where it had got to.
        foreach ($mailboxes as $key => $since) {
            $checked = Setting::get(MatterReplyCollector::checkedKey($key));

            if ($checked === null || $since->lt($checked)) {
                Setting::set(MatterReplyCollector::checkedKey($key), $since->toDateTimeString(), 'mail');
            }
        }

        // The next automatic check at once, not in up to ten minutes.
        if ($mailboxes !== []) {
            Setting::set(MatterReplyCollector::LAST_RUN, ['stopped' => true, 'finished_at' => now()->toDateTimeString()] + (array) Setting::get(MatterReplyCollector::LAST_RUN, []), 'mail', 'json');
        }

        return $result;
    }

    /**
     * @param  array{undone: int, unread: int, errors: list<string>}  $result
     */
    private function undoOne(MatterEmail $email, array &$result): void
    {
        // Unread in the mailbox again — only one known to have been unread before.
        if ($email->was_unread === true && $this->collector->markUnread($email)) {
            $result['unread']++;
        }

        // Its files in the system and the matter's OneDrive, its step in the progress, its contacts, and itself.
        $this->deletion->delete($email);
    }
}
