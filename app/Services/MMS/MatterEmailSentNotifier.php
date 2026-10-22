<?php

namespace App\Services\MMS;

use App\Enums\NotificationType;
use App\Filament\Mms\Resources\Matters\MatterResource;
use App\Models\Matter;
use App\Models\MatterEmail;
use App\Models\User;
use App\Services\Notifications\Notifier;
use App\Support\PendingBatch;
use ArrayObject;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * An email sent on a matter told to its people — its experts, assistants and their administrators — but not
 * to whoever sent it: in their bell's Emails tab. What one send did to a matter (minutes to each attendee) is
 * one notification, sent once the request is done.
 */
final class MatterEmailSentNotifier
{
    public static function note(MatterEmail $email): void
    {
        if ($email->matter_id === null) {
            return;
        }

        $pending = self::pending();
        $matter = (int) $email->matter_id;
        $entry = $pending[$matter] ?? ['by' => $email->user_id, 'subjects' => []];
        $entry['subjects'][] = trim((string) $email->subject);
        $pending[$matter] = $entry;
    }

    /** Run once the request is done; by hand in tests. */
    public static function flush(): void
    {
        $pending = self::pending()->getArrayCopy();
        self::pending()->exchangeArray([]);
        if ($pending === []) {
            return;
        }

        // The matters and their people, read at once.
        $matters = Matter::query()->whereKey(array_keys($pending))->get(['id', 'year', 'number'])->keyBy('id');
        $senders = User::query()->whereKey(array_filter(array_column($pending, 'by')))->get()->keyBy('id');

        foreach ($pending as $matterId => $entry) {
            $matter = $matters->get($matterId);
            if (! $matter) {
                continue;
            }

            $users = MatterPeople::of([$matterId])->reject(fn (User $user): bool => $user->getKey() === $entry['by']);
            if ($users->isEmpty()) {
                continue;
            }

            $by = $senders->get($entry['by']);
            $subjects = array_values(array_unique(array_filter($entry['subjects'])));
            $url = MatterResource::getUrl('view', ['record' => $matter, 'tab' => 'emails'], panel: 'mms');

            try {
                Notifier::send(
                    NotificationType::MATTER_EMAIL_SENT,
                    $users,
                    trans_choice(':name sent an email on matter :matter|:name sent :count emails on matter :matter', count($entry['subjects']), [
                        'name' => $by?->display_name ?: ($by?->name ?? __('The system')),
                        'matter' => $matter->year.'/'.$matter->number,
                        'count' => count($entry['subjects']),
                    ]),
                    implode("\n", $subjects),
                    $url,
                    fn (Notification $notification) => $notification
                        ->icon('heroicon-o-paper-airplane')
                        ->iconColor('info')
                        ->body(MatterReplyCollector::bullets($subjects)),
                );
            } catch (Throwable $e) {
                Log::info('Sent email notification not sent: '.$e->getMessage());
            }
        }
    }

    private static function pending(): ArrayObject
    {
        return PendingBatch::for(self::class, fn () => self::flush());
    }
}
