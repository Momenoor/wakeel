<?php

namespace App\Filament\Pms\Actions;

use Closure;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;

/**
 * The step before a PMS delete: a record that is part of a lease's or a
 * quotation's history says why it can't go (deletionBlockedReason() on the
 * model, which also refuses the delete itself), and the reader gets that
 * as a notification instead of an error page.
 */
final class KeepsHistory
{
    public static function guard(): Closure
    {
        return function (Model $record, DeleteAction $action): void {
            $reason = $record->deletionBlockedReason();

            if ($reason === null) {
                return;
            }

            Notification::make()
                ->danger()
                ->title(__('Could not continue'))
                ->body($reason)
                ->send();

            $action->halt();
        };
    }
}
