<?php

namespace App\Filament\Shared\Pages;

use App\Models\Setting;
use App\Support\ScreenPermissions;
use BackedEnum;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;

/**
 * Settings → Maintenance → Maintenance & Offline: whether the application
 * is open to everyone or in maintenance mode, and who may still get in.
 */
class OfflineSettings extends SettingsFormPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::SignalSlash;

    protected static ?int $navigationSort = 60;

    public static function getNavigationLabel(): string
    {
        return __('Maintenance & Offline');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Maintenance');
    }

    protected static function permission(): string
    {
        return ScreenPermissions::SETTINGS_MAINTENANCE_TAB;
    }

    protected function defaults(): array
    {
        return [
            'app_offline' => Setting::get('app_offline', false),
            'offline_message' => Setting::get('offline_message', __('System is currently undergoing scheduled maintenance. Please check back later.')),
            'offline_allow_admins' => Setting::get('offline_allow_admins', true),
        ];
    }

    protected function groups(): array
    {
        return [
            'app_offline' => 'system',
            'offline_message' => 'system',
            'offline_allow_admins' => 'system',
        ];
    }

    protected function fields(): array
    {
        return [
            Section::make(__('System Status & Offline Mode'))
                ->description(__('Control whether the application is accessible to regular users or put into maintenance mode.'))
                ->icon(Heroicon::Signal)
                ->schema([
                    Toggle::make('app_offline')
                        ->label(__('Enable Offline / Maintenance Mode'))
                        ->helperText(__('When enabled, regular users will be redirected to the maintenance page.'))
                        ->live()
                        ->columnSpanFull(),

                    Textarea::make('offline_message')
                        ->label(__('Maintenance Message'))
                        ->helperText(__('Custom message displayed to users during offline maintenance.'))
                        ->rows(3)
                        ->columnSpanFull()
                        ->visible(fn (Get $get): bool => (bool) $get('app_offline')),

                    Toggle::make('offline_allow_admins')
                        ->label(__('Allow Administrators to Access While Offline'))
                        ->helperText(__('Super administrators and administrators will retain full access to manage the system.'))
                        ->default(true)
                        ->columnSpanFull(),
                ]),
        ];
    }
}
