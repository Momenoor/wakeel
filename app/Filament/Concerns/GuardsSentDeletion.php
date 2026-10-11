<?php

namespace App\Filament\Concerns;

use App\Models\MatterLetter;
use App\Models\MatterMinutes;
use Filament\Actions\Action;
use Illuminate\Database\Eloquent\Model;

/**
 * A letter or minutes already sent is a record of what went out: deleted by
 * a super admin only. For anyone else its Delete is there but disabled,
 * saying why.
 */
trait GuardsSentDeletion
{
    /** Unsent — or a super admin's to delete. */
    public static function mayDeleteSent(MatterLetter|MatterMinutes $record): bool
    {
        return ! $record->wasSent() || (auth()->user()?->isSuperAdmin() ?? false);
    }

    protected static function guardSentDeletion(Action $action): Action
    {
        return $action
            ->disabled(fn (Model $record): bool => ! static::mayDeleteSent($record))
            ->tooltip(fn (Model $record): ?string => static::mayDeleteSent($record) ? null : __('Already sent: only a super admin can delete it.'));
    }
}
