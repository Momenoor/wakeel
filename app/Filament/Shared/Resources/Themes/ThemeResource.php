<?php

namespace App\Filament\Shared\Resources\Themes;

use App\Enums\ThemeDensity;
use App\Enums\ThemeRadius;
use App\Enums\ThemeSidebar;
use App\Filament\Shared\Clusters\Settings;
use App\Filament\Shared\Resources\Themes\Pages\ManageThemes;
use App\Models\LetterFont;
use App\Models\Setting;
use App\Models\Theme;
use App\Support\ScreenPermissions;
use App\Support\ThemeManager;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Colors\Color;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ColorColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

/**
 * Settings → Themes: the looks the system can take. One is the office's; each user may choose another in their
 * profile, unless the office keeps everyone to its own (App\Support\ThemeManager).
 */
class ThemeResource extends Resource
{
    protected static ?string $model = Theme::class;

    protected static ?string $cluster = Settings::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSwatch;

    protected static ?int $navigationSort = 12;

    public static function getNavigationGroup(): ?string
    {
        return __('General');
    }

    public static function getModelLabel(): string
    {
        return __('Theme');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Themes');
    }

    /** Whoever looks after the General settings (the logos, the system's name) looks after its look too. */
    public static function canAccess(): bool
    {
        return (auth()->user()?->can('View:SystemSettings') ?? false) && ScreenPermissions::can(ScreenPermissions::SETTINGS_GENERAL_TAB);
    }

    public static function form(Schema $schema): Schema
    {
        $fonts = fn (): array => LetterFont::query()->orderBy('name')->pluck('name', 'id')->all();

        return $schema->columns(2)->components([
            TextInput::make('name')
                ->label(__('Name'))
                ->required()
                ->maxLength(100)
                ->columnSpanFull(),

            Section::make(__('Colours'))
                ->description(__('Pick each colour once: its lighter and darker shades are made from it. One left empty keeps the standard colour.'))
                ->columns(3)
                ->columnSpanFull()
                ->schema(collect(Theme::COLORS)->map(fn (string $name): ColorPicker => ColorPicker::make('colors.'.$name)
                    ->label(self::colorLabel($name))
                    ->hex()
                    ->regex('/^#[0-9a-fA-F]{6}$/')
                    // Live: the preview below follows each change.
                    ->live(onBlur: true))->all()),

            Section::make(__('Shape'))
                ->columns(3)
                ->columnSpanFull()
                ->schema([
                    ToggleButtons::make('radius')->label(__('Corners'))->options(ThemeRadius::class)->default(ThemeRadius::MEDIUM)->required()->live(),
                    ToggleButtons::make('density')->label(__('Spacing'))->options(ThemeDensity::class)->default(ThemeDensity::NORMAL)->required()->live(),
                    ToggleButtons::make('sidebar')->label(__('Sidebar'))->options(ThemeSidebar::class)->default(ThemeSidebar::PLAIN)->required()->live(),
                ]),

            Section::make(__('Fonts'))
                ->description(__('From the fonts uploaded under Templates → Fonts. Empty: the office font.'))
                ->columns(2)
                ->columnSpanFull()
                ->schema([
                    Select::make('latin_font_id')->label(__('English text'))->options($fonts)->searchable(),
                    Select::make('arabic_font_id')->label(__('Arabic text'))->options($fonts)->searchable(),
                ]),

            Placeholder::make('preview')
                ->label(__('Preview'))
                ->columnSpanFull()
                ->content(fn (Get $get): HtmlString => self::preview($get)),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label(__('Name'))->weight('bold')->searchable(),
                ColorColumn::make('colors.primary')->label(self::colorLabel('primary')),
                ColorColumn::make('colors.gray')->label(self::colorLabel('gray')),
                TextColumn::make('radius')->label(__('Corners'))->badge()->color('gray'),
                TextColumn::make('density')->label(__('Spacing'))->badge()->color('gray'),
                IconColumn::make('office')
                    ->label(__('Office theme'))
                    ->state(fn (Theme $record): bool => self::isOffice($record))
                    ->boolean(),
                TextColumn::make('users_count')->label(__('Users'))->counts('users'),
            ])
            ->recordActions([
                Action::make('useForOffice')
                    ->label(__('Use for the office'))
                    ->icon(Heroicon::OutlinedCheckBadge)
                    ->color('gray')
                    ->visible(fn (Theme $record): bool => ! self::isOffice($record))
                    ->requiresConfirmation()
                    ->action(function (Theme $record, $livewire): void {
                        Setting::set(ThemeManager::SETTING, $record->getKey(), 'appearance');
                        Notification::make()->success()->title(__(':name is now the office theme.', ['name' => $record->name]))->send();
                        // Reloaded: the page itself takes the new colours.
                        $livewire->js('window.location.reload()');
                    }),
                EditAction::make()->modalWidth('5xl'),
                DeleteAction::make(),
            ])
            ->emptyStateHeading(__('No themes yet'))
            ->emptyStateDescription(__('Add the starter themes, or make your own.'));
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageThemes::route('/'),
        ];
    }

    public static function isOffice(Theme $theme): bool
    {
        return (string) Setting::get(ThemeManager::SETTING) === (string) $theme->getKey();
    }

    public static function colorLabel(string $name): string
    {
        return match ($name) {
            'primary' => __('Primary'),
            'gray' => __('Gray'),
            'info' => __('Info'),
            'success' => __('Success'),
            'warning' => __('Warning'),
            'danger' => __('Danger'),
            default => $name,
        };
    }

    /**
     * A few buttons and badges in the colours and corners being chosen — drawn with inline styles from the form
     * itself, before anything is saved.
     */
    private static function preview(Get $get): HtmlString
    {
        $colors = (array) $get('colors');
        $valid = fn (mixed $hex): bool => is_string($hex) && preg_match('/^#[0-9a-f]{6}$/i', $hex) === 1;
        $hex = fn (string $name, string $fallback): string => $valid($colors[$name] ?? null) ? $colors[$name] : $fallback;
        $radius = $get('radius');
        $radius = $radius instanceof ThemeRadius ? $radius : (ThemeRadius::tryFrom((string) $radius) ?? ThemeRadius::MEDIUM);
        $corner = round(0.5 * $radius->scale(), 3).'rem';

        $button = fn (string $name, string $fallback, string $label): string => '<span style="display:inline-block;padding:.4rem .9rem;margin:.2rem;border-radius:'.$corner.';color:#fff;background:'.e($hex($name, $fallback)).';">'.e($label).'</span>';
        $badge = fn (string $name, string $fallback, string $label): string => '<span style="display:inline-block;padding:.1rem .6rem;margin:.2rem;border-radius:'.$corner.';color:'.e($hex($name, $fallback)).';border:1px solid '.e($hex($name, $fallback)).';">'.e($label).'</span>';

        // Unreadable white text on a light primary is told now, not discovered later.
        $hardToRead = $valid($colors['primary'] ?? null) && ! Color::isTextContrastRatioAccessible('#ffffff', $colors['primary']);

        return new HtmlString(
            '<div style="padding:1rem;border:1px solid '.e($hex('gray', '#e5e7eb')).';border-radius:'.$corner.';">'
            .$button('primary', '#2563eb', __('Save'))
            .$button('danger', '#dc2626', __('Delete'))
            .$button('gray', '#6b7280', __('Cancel'))
            .'<div style="margin-top:.5rem;">'
            .$badge('success', '#16a34a', __('Paid'))
            .$badge('warning', '#d97706', __('Pending'))
            .$badge('info', '#0284c7', __('New'))
            .$badge('danger', '#dc2626', __('Overdue'))
            .'</div></div>'
            .($hardToRead ? '<p style="color:#b45309;margin-top:.5rem;">'.e(__('White text is hard to read on this primary colour — choose a darker one.')).'</p>' : '')
        );
    }
}
