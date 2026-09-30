<?php

namespace App\Filament\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * A relation manager shown only to roles holding its own permission
 * (App\Support\ScreenPermissions) — on top of whatever Filament already
 * checks for the related records.
 */
trait HasRelationManagerPermission
{
    abstract public static function viewPermission(): string;

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return (auth()->user()?->can(static::viewPermission()) ?? false)
            && parent::canViewForRecord($ownerRecord, $pageClass);
    }
}
