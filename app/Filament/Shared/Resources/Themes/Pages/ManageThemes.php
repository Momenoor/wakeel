<?php

namespace App\Filament\Shared\Resources\Themes\Pages;

use App\Enums\ThemeDensity;
use App\Enums\ThemeRadius;
use App\Enums\ThemeSidebar;
use App\Filament\Shared\Resources\Themes\ThemeResource;
use App\Models\Setting;
use App\Models\Theme;
use App\Support\ThemeManager;
use App\Support\ThemeOptions;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Icons\Heroicon;

class ManageThemes extends ManageRecords
{
    protected static string $resource = ThemeResource::class;

    /** Filament's grays: a theme in one of them is gray all through; any other is shown on the standard gray. */
    private const GRAYS = ['slate', 'gray', 'zinc', 'neutral', 'stone', 'mauve', 'olive', 'mist', 'taupe'];

    /**
     * Ready-made looks to start from: the system as it came (blue for matters, green for properties) and one in
     * each of Filament's palettes. Add them, then copy or change any.
     *
     * @return array<string, array<string, string>> name => colours
     */
    public static function starters(): array
    {
        $palettes = collect(ThemeOptions::palettes())
            ->mapWithKeys(fn (string $label, string $palette): array => ['Filament '.ucfirst($palette) => [
                'primary' => $palette,
                'gray' => in_array($palette, self::GRAYS, true) ? $palette : 'gray',
            ]])
            ->all();

        return [
            'Original — Matters' => ['primary' => 'blue', 'gray' => 'gray'],
            'Original — Properties' => ['primary' => 'green', 'gray' => 'gray'],
            ...$palettes,
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->modalWidth('5xl'),

            Action::make('starters')
                ->label(__('Add starter themes'))
                ->icon(Heroicon::OutlinedSparkles)
                ->color('gray')
                ->modalDescription(__('The system\'s original looks and one in each of Filament\'s colours. Themes already there are left as they are.'))
                ->requiresConfirmation()
                ->action(function (): void {
                    $added = 0;

                    foreach (self::starters() as $name => $colors) {
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
