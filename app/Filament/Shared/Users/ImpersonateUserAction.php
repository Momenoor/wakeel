<?php

namespace App\Filament\Shared\Users;

use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Support\Concerns\EvaluatesClosures;
use TomatoPHP\FilamentUsers\Concerns\Impersonates;

/**
 * "Impersonate user" on the users table, in place of the package's own
 * (config/filament-users.php turns that off). Same impersonation — the
 * package's Impersonates concern — but the label is translated when the
 * table is drawn, so it follows the language the reader chose, and after
 * impersonating it goes to the panel it was pressed in.
 */
class ImpersonateUserAction
{
    use EvaluatesClosures;
    use Impersonates;

    public static function make(): Action
    {
        $impersonator = (new self)
            ->guard(config('filament-users.impersonate.auth_guard', 'web'))
            ->redirectTo(fn (): string => Filament::getCurrentOrDefaultPanel()->getUrl());

        return Action::make('impersonate')
            ->iconButton()
            ->requiresConfirmation()
            ->icon('heroicon-o-user-circle')
            ->color('info')
            ->label(fn (): string => trans('filament-users::user.resource.title.impersonate'))
            ->tooltip(fn (): string => trans('filament-users::user.resource.title.impersonate'))
            ->action(fn ($record) => $impersonator->impersonate($record))
            ->hidden(fn ($record): bool => ! $impersonator->canBeImpersonated($record));
    }
}
