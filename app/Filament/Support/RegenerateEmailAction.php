<?php

namespace App\Filament\Support;

use App\Jobs\EmailJob;
use App\Jobs\RegenerateEmail;
use App\Models\MatterEmail;
use Closure;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

/**
 * A received email's PDF made again (its letters garbled, say): read again from the mailbox it came to, its
 * PDF made anew, its original (.eml) and attachments downloaded again, each in place of the old one
 * (RegenerateEmail, in the background). On a matter's Emails tab and the unfiled emails.
 */
class RegenerateEmailAction
{
    /**
     * @param  Closure(MatterEmail): bool  $allowed  whether this user may do it to this email
     */
    public static function make(Closure $allowed): Action
    {
        return Action::make('regenerate')
            ->label(__('Make the PDF again'))
            ->icon('heroicon-o-arrow-path')
            ->color('gray')
            ->visible(fn (MatterEmail $record): bool => $record->direction === MatterEmail::RECEIVED && filled($record->message_id) && $allowed($record))
            ->requiresConfirmation()
            ->modalHeading(__('Make the PDF again'))
            ->modalDescription(__('The email is read again from the mailbox it came to: its PDF made anew, its original (.eml) and its attachments downloaded again, each put in place of the old one — in the system and in the matter\'s OneDrive.'))
            ->modalSubmitActionLabel(__('Make it again'))
            // In the background (read from the mailbox, made, put in OneDrive): told in the bell when done.
            ->databaseTransaction(false)
            ->action(fn (MatterEmail $record) => match ((new RegenerateEmail((int) $record->getKey(), auth()->id()))->start()) {
                EmailJob::BUSY => Notification::make()->warning()->title(__('Something is already being done to this email. Try again in a minute.'))->send(),
                EmailJob::QUEUED => Notification::make()->success()->title(__('Being made again: you are told in the bell when it is done.'))->send(),
                default => null,
            })
            ->hidden(fn (MatterEmail $record, $livewire): bool => EmailJob::busy((int) $record->getKey(), method_exists($livewire, 'getTableRecords') ? $livewire->getTableRecords() : null) !== null);
    }
}
