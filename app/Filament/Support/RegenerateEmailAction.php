<?php

namespace App\Filament\Support;

use App\Jobs\EmailJob;
use App\Jobs\RegenerateEmail;
use App\Models\MatterEmail;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Collection;

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

    /**
     * The same to the emails ticked: each made again in the background, those it is not for (sent, or not
     * found in a mailbox) left.
     *
     * @param  Closure(MatterEmail): bool  $allowed
     */
    public static function bulk(Closure $allowed): BulkAction
    {
        return BulkAction::make('regenerateAll')
            ->label(__('Make the PDF again'))
            ->icon('heroicon-o-arrow-path')
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading(__('Make the PDF again'))
            ->modalDescription(__('Each email is read again from the mailbox it came to: its PDF made anew, its original (.eml) and its attachments downloaded again, each put in place of the old one — in the system and in the matter\'s OneDrive.'))
            ->databaseTransaction(false)
            ->deselectRecordsAfterCompletion()
            ->action(function (Collection $records) use ($allowed): void {
                $started = $records
                    ->filter(fn (MatterEmail $record): bool => $record->direction === MatterEmail::RECEIVED && filled($record->message_id) && $allowed($record))
                    ->map(fn (MatterEmail $record): string => (new RegenerateEmail((int) $record->getKey(), auth()->id()))->start())
                    ->countBy();

                Notification::make()
                    ->success()
                    ->title(__('Being made again: :count emails.', ['count' => ($started[EmailJob::QUEUED] ?? 0) + ($started[EmailJob::DONE] ?? 0)]))
                    ->body(($started[EmailJob::QUEUED] ?? 0) ? __('You are told in the bell when each is done.') : null)
                    ->send();
            });
    }
}
