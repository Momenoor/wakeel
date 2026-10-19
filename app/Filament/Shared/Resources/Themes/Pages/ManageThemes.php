<?php

namespace App\Filament\Shared\Resources\Themes\Pages;

use App\Enums\ThemeDensity;
use App\Enums\ThemeRadius;
use App\Enums\ThemeSidebar;
use App\Filament\Shared\Resources\Themes\ThemeResource;
use App\Models\Setting;
use App\Models\Theme;
use App\Support\ThemeManager;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Icons\Heroicon;

class ManageThemes extends ManageRecords
{
    protected static string $resource = ThemeResource::class;

    /** Ready-made looks to start from: add them, then change any. */
    public const STARTERS = [
        'Royal Blue' => ['primary' => '#1d4ed8', 'gray' => '#64748b'],
        'Emerald' => ['primary' => '#047857', 'gray' => '#6b7280'],
        'Desert Sand' => ['primary' => '#b45309', 'gray' => '#78716c'],
        'Graphite' => ['primary' => '#374151', 'gray' => '#71717a'],
        'Burgundy' => ['primary' => '#9f1239', 'gray' => '#6b7280'],
    ];

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->modalWidth('5xl'),

            Action::make('starters')
                ->label(__('Add starter themes'))
                ->icon(Heroicon::OutlinedSparkles)
                ->color('gray')
                ->action(function (): void {
                    $added = 0;

                    foreach (self::STARTERS as $name => $colors) {
                        $theme = Theme::firstOrCreate(['name' => $name], [
                            'colors' => $colors,
                            'radius' => ThemeRadius::MEDIUM,
                            'density' => ThemeDensity::NORMAL,
                            'sidebar' => ThemeSidebar::PLAIN,
                        ]);
                        $added += $theme->wasRecentlyCreated ? 1 : 0;
                    }

                    Notification::make()->success()->title(__(':count themes added.', ['count' => $added]))->send();
                }),

            // Everyone in the office's theme, or each in their own.
            Action::make('lock')
                ->label(fn (): string => app(ThemeManager::class)->allowsPersonal() ? __('Keep everyone to the office theme') : __('Let each user choose'))
                ->icon(fn (): Heroicon => app(ThemeManager::class)->allowsPersonal() ? Heroicon::OutlinedLockClosed : Heroicon::OutlinedLockOpen)
                ->color('gray')
                ->requiresConfirmation()
                ->action(function (): void {
                    Setting::set(ThemeManager::LOCKED, app(ThemeManager::class)->allowsPersonal(), 'appearance');
                    Notification::make()->success()->title(__('Saved'))->send();
                }),

            Action::make('standard')
                ->label(__('Back to the standard look'))
                ->icon(Heroicon::OutlinedArrowUturnLeft)
                ->color('gray')
                ->visible(fn (): bool => filled(Setting::get(ThemeManager::SETTING)))
                ->requiresConfirmation()
                ->action(function (): void {
                    Setting::set(ThemeManager::SETTING, null, 'appearance');
                    $this->js('window.location.reload()');
                }),
        ];
    }
}
