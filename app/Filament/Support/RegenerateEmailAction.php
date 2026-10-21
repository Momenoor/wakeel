<?php

namespace App\Filament\Support;

use App\Models\MatterEmail;
use App\Services\MMS\MatterReplyCollector;
use Closure;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Throwable;

/**
 * A received email's PDF made again (its letters garbled, say): read again from the mailbox it came to, its
 * PDF made anew, its original (.eml) and attachments downloaded again, each in place of the old one
 * (MatterReplyCollector::regenerate). On a matter's Emails tab and the unfiled emails.
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
            ->action(function (MatterEmail $record): void {
                try {
                    $done = app(MatterReplyCollector::class)->regenerate($record);
                } catch (Throwable $e) {
                    Notification::make()->danger()->title(__('The email could not be made again'))->body($e->getMessage())->send();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title(__('Made again'))
                    ->body(collect([
                        $done['pdf'] ? __('PDF replaced') : null,
                        $done['eml'] ? __('Original (.eml) replaced') : null,
                        $done['attachments'] ? trans_choice(':count attachment downloaded again|:count attachments downloaded again', $done['attachments'], ['count' => $done['attachments']]) : null,
                    ])->filter()->implode(' · '))
                    ->send();
            });
    }
}
