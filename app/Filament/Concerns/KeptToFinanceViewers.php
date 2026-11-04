<?php

namespace App\Filament\Concerns;

use App\Support\FinanceViewers;

/**
 * A resource about salaries or incentives, gated by its module (HasModuleGate) and, when users are chosen under
 * Settings → General, kept to those users alone (FinanceViewers) — hidden from the navigation and refused at its
 * address to everyone else, whatever their permissions.
 */
trait KeptToFinanceViewers
{
    use HasModuleGate {
        HasModuleGate::canAccess as moduleCanAccess;
        HasModuleGate::shouldRegisterNavigation as moduleShouldRegisterNavigation;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return FinanceViewers::allows(auth()->user()) && static::moduleShouldRegisterNavigation();
    }

    public static function canAccess(): bool
    {
        return FinanceViewers::allows(auth()->user()) && static::moduleCanAccess();
    }
}
