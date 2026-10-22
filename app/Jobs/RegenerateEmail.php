<?php

namespace App\Jobs;

use App\Models\MatterEmail;
use App\Services\MMS\MatterReplyCollector;
use Filament\Notifications\Notification;

/**
 * A received email made again (its letters garbled, say): read again from the mailbox it came to, its PDF made
 * anew, its original (.eml) and attachments downloaded again, each in place of the old one
 * (MatterReplyCollector::regenerate) — in the background (EmailJob).
 */
class RegenerateEmail extends EmailJob
{
    protected function work(MatterEmail $email): ?Notification
    {
        $done = app(MatterReplyCollector::class)->regenerate($email);

        return Notification::make()
            ->success()
            ->title(__('Made again'))
            ->body(collect([
                $email->subject,
                $done['pdf'] ? __('PDF replaced') : null,
                $done['eml'] ? __('Original (.eml) replaced') : null,
                $done['attachments'] ? trans_choice(':count attachment downloaded again|:count attachments downloaded again', $done['attachments'], ['count' => $done['attachments']]) : null,
            ])->filter()->implode(' · '));
    }

    protected function failedTitle(): string
    {
        return __('The email could not be made again');
    }

    protected function doing(): string
    {
        return __('PDF being made again…');
    }
}
