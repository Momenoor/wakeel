<?php

namespace App\Filament\Shared\Pages;

use App\Support\AppUpdate;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Added in 1.0.23 only to prove the one-click updater delivers new code:
 * after updating, this page appearing in the sidebar is the proof. Remove
 * it (and its registration in both panel providers) once confirmed.
 */
class UpdateTest extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::Beaker;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 101;

    protected string $view = 'filament.shared.update-test';

    public static function getNavigationLabel(): string
    {
        return __('Update Test');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Settings');
    }

    public function getTitle(): string
    {
        return __('Update Test');
    }

    public static function canAccess(): bool
    {
        return AppUpdate::canManage();
    }
}
