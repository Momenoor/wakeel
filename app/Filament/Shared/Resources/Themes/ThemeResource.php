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
use App\Support\ThemeOptions;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
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
 * profile, unless the office keeps everyone to its own (App\Support\ThemeManager). The fields of the options are
 * built from App\Support\ThemeOptions.
 */
class ThemeResource extends Resource
{
    protected static ?string $model = Theme::class;

    protected static ?string $cluster = Settings::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSwatch;

    protected static ?int $navigationSort = 12;

    /** A colour's choice when it is not one of Filament's palettes. */
    private const CUSTOM = 'custom';

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

        return $schema->components([
            TextInput::make('name')
                ->label(__('Name'))
                ->required()
                ->maxLength(100)
                ->columnSpanFull(),

            Tabs::make()
                ->columnSpanFull()
                ->tabs([
                    Tab::make(__('Colours'))
                        ->icon(Heroicon::OutlinedSwatch)
                        ->schema([
                            Section::make()
                                ->description(__('Each colour is one of Filament\'s palettes, or your own: its lighter and darker shades are made from it. One left empty keeps the standard colour.'))
                                ->columns(2)
                                ->schema(collect(Theme::COLORS)->flatMap(fn (string $name): array => self::colorFields($name))->all()),
                        ]),

                    Tab::make(__('Layout'))
                        ->icon(Heroicon::OutlinedRectangleGroup)
                        ->columns(2)
                        ->schema([
                            self::optionField('navigation'),
                            ToggleButtons::make('sidebar')->label(__('Sidebar'))->options(ThemeSidebar::class)->default(ThemeSidebar::PLAIN)->required()->inline()->live(),
                            self::optionField('sidebar_width'),
                            self::optionField('sidebar_collapse'),
                            self::optionField('topbar_style'),
                            self::optionField('content_width'),
                            ToggleButtons::make('radius')->label(__('Corners'))->options(ThemeRadius::class)->default(ThemeRadius::MEDIUM)->required()->inline()->live(),
                            ToggleButtons::make('density')->label(__('Spacing'))->options(ThemeDensity::class)->default(ThemeDensity::NORMAL)->required()->inline(),
                            self::optionField('collapsible_groups'),
                        ]),

                    Tab::make(__('Light and dark'))
                        ->icon(Heroicon::OutlinedMoon)
                        ->schema([
                            Grid::make(2)->schema([
                                self::optionField('mode'),
                                self::optionField('mode_switch'),
                            ]),
                            Section::make(__('Surfaces'))
                                ->description(__('A colour for light mode and one for dark mode; empty keeps the standard one. Words on the top bar and the sidebar turn white or dark to stay readable.'))
                                ->columns(2)
                                ->schema(collect(ThemeOptions::SURFACES)->flatMap(fn (string $surface): array => [
                                    ColorPicker::make("options.surfaces.{$surface}.light")
                                        ->label(ThemeOptions::surfaceLabel($surface).' — '.__('Light mode'))
                                        ->hex()
                                        ->regex('/^#[0-9a-fA-F]{6}$/'),
                                    ColorPicker::make("options.surfaces.{$surface}.dark")
                                        ->label(ThemeOptions::surfaceLabel($surface).' — '.__('Dark mode'))
                                        ->hex()
                                        ->regex('/^#[0-9a-fA-F]{6}$/'),
                                ])->all()),
                        ]),

                    Tab::make(__('Look and fonts'))
                        ->icon(Heroicon::OutlinedPaintBrush)
                        ->columns(2)
                        ->schema([
                            self::optionField('cards'),
                            self::optionField('headings'),
                            Select::make('latin_font_id')->label(__('English text'))->options($fonts)->searchable()
                                ->helperText(__('From the fonts uploaded under Templates → Fonts. Empty: the office font.')),
                            Select::make('arabic_font_id')->label(__('Arabic text'))->options($fonts)->searchable(),
                        ]),

                    Tab::make(__('Branding'))
                        ->icon(Heroicon::OutlinedPhoto)
                        ->columns(2)
                        ->schema([
                            self::optionField('logo'),
                            self::optionField('logo_dark'),
                            self::optionField('logo_height'),
                            self::optionField('login_background'),
                        ]),
                ]),

            Placeholder::make('preview')
                ->label(__('Preview'))
                ->columnSpanFull()
                ->content(fn (Get $get): HtmlString => self::preview($get)),
        ]);
    }

    /**
     * A colour: one of Filament's palettes (each shown with its swatch) or "your own", which opens a colour picker.
     * Stored as the palette's name or the hex value.
     *
     * @return list<Field>
     */
    private static function colorFields(string $name): array
    {
        $palettes = collect(ThemeOptions::palettes())
            ->mapWithKeys(fn (string $label, string $palette): array => [$palette => '<span style="display:inline-block;width:.9rem;height:.9rem;border-radius:9999px;vertical-align:middle;margin-inline-end:.4rem;background:'.Color::all()[$palette][500].';"></span>'.e($label)])
            ->all();

        return [
            Select::make("colors.{$name}")
                ->label(self::colorLabel($name))
                ->options([...$palettes, self::CUSTOM => e(__('Your own colour…'))])
                ->allowHtml()
                ->native(false)
                ->searchable()
                ->placeholder(__('Standard'))
                ->live()
                // A hex value stored: "your own", with the picker holding it.
                ->afterStateHydrated(function (Select $component, $state, Set $set) use ($name): void {
                    if (is_string($state) && str_starts_with($state, '#')) {
                        $component->state(self::CUSTOM);
                        $set("custom_colors.{$name}", $state);
                    }
                })
                ->dehydrateStateUsing(fn ($state, Get $get) => $state === self::CUSTOM ? $get("custom_colors.{$name}") : $state),
            ColorPicker::make("custom_colors.{$name}")
                ->label(__('Your own :colour', ['colour' => self::colorLabel($name)]))
                ->hex()
                ->regex('/^#[0-9a-fA-F]{6}$/')
                ->required(fn (Get $get): bool => $get("colors.{$name}") === self::CUSTOM)
                ->visible(fn (Get $get): bool => $get("colors.{$name}") === self::CUSTOM)
                ->live(onBlur: true)
                // Kept in "colors" (above), not here.
                ->dehydrated(false),
        ];
    }

    /** The field for one of App\Support\ThemeOptions, by its kind. */
    private static function optionField(string $key): Field
    {
        $option = ThemeOptions::all()[$key];
        $name = "options.{$key}";

        $field = match ($option['kind']) {
            'toggle' => Toggle::make($name)->inline(false),
            'image' => FileUpload::make($name)->image()->disk(ThemeOptions::DISK)->directory(ThemeOptions::DIRECTORY)->maxSize(2048),
            default => ToggleButtons::make($name)->options($option['options'])->inline()->live(),
        };

        return $field
            ->label($option['label'])
            ->default($option['default'])
            ->helperText($option['help'] ?? null);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label(__('Name'))->weight('bold')->searchable(),
                ColorColumn::make('primary_swatch')->label(self::colorLabel('primary'))
                    ->state(fn (Theme $record): ?string => ThemeOptions::css($record->colors['primary'] ?? null)),
                ColorColumn::make('gray_swatch')->label(self::colorLabel('gray'))
                    ->state(fn (Theme $record): ?string => ThemeOptions::css($record->colors['gray'] ?? null)),
                TextColumn::make('navigation')->label(__('Navigation'))
                    ->state(fn (Theme $record): string => ThemeOptions::all()['navigation']['options'][$record->option('navigation')] ?? '')
                    ->badge()->color('gray'),
                TextColumn::make('radius')->label(__('Corners'))->badge()->color('gray'),
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
                        // Reloaded: the page itself takes the new look.
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
            'secondary' => __('Secondary'),
            'gray' => __('Gray'),
            'info' => __('Info'),
            'success' => __('Success'),
            'warning' => __('Warning'),
            'danger' => __('Danger'),
            default => $name,
        };
    }

    /**
     * A small page in the colours, surfaces and corners being chosen — drawn with inline styles from the form
     * itself, before anything is saved.
     */
    private static function preview(Get $get): HtmlString
    {
        $color = function (string $name, string $fallback) use ($get): string {
            $value = $get("colors.{$name}");

            return ThemeOptions::css($value === self::CUSTOM ? $get("custom_colors.{$name}") : $value) ?? $fallback;
        };
        $surface = fn (string $name, string $fallback): string => ThemeOptions::css($get("options.surfaces.{$name}.light")) ?? $fallback;

        $radius = $get('radius');
        $radius = $radius instanceof ThemeRadius ? $radius : (ThemeRadius::tryFrom((string) $radius) ?? ThemeRadius::MEDIUM);
        $corner = round(0.5 * $radius->scale(), 3).'rem';
        $primary = $color('primary', '#2563eb');

        $sidebarStyle = $get('sidebar');
        $sidebar = $surface('sidebar', ($sidebarStyle instanceof ThemeSidebar ? $sidebarStyle : ThemeSidebar::tryFrom((string) $sidebarStyle)) === ThemeSidebar::COLORED ? 'color-mix(in oklab, '.$primary.' 70%, black)' : '#ffffff');
        $topbar = $surface('topbar', $get('options.topbar_style') === 'primary' ? $primary : '#ffffff');
        // Dark words on a light hex colour; white on anything else (a dark one, a palette shade, a mix).
        $ink = fn (string $background): string => str_starts_with($background, '#') && Color::isLight($background) ? '#111827' : '#ffffff';

        $button = fn (string $background, string $label): string => '<span style="display:inline-block;padding:.35rem .8rem;margin:.15rem;border-radius:'.$corner.';color:#fff;background:'.e($background).';">'.e($label).'</span>';
        $badge = fn (string $tone, string $label): string => '<span style="display:inline-block;padding:.05rem .55rem;margin:.15rem;border-radius:'.$corner.';color:'.e($tone).';border:1px solid '.e($tone).';">'.e($label).'</span>';

        $primaryHex = $get('colors.primary') === self::CUSTOM ? $get('custom_colors.primary') : null;
        $hardToRead = is_string($primaryHex) && preg_match('/^#[0-9a-f]{6}$/i', $primaryHex) === 1 && ! Color::isTextContrastRatioAccessible('#ffffff', $primaryHex);
        $isTop = $get('options.navigation') === 'top';

        return new HtmlString(
            '<div style="display:flex;flex-direction:column;border:1px solid #e5e7eb;border-radius:'.$corner.';overflow:hidden;background:'.e($surface('background', '#f9fafb')).';">'
            .'<div style="padding:.5rem .8rem;background:'.e($topbar).';color:'.e($ink($topbar)).';font-weight:600;">'.e(__('Top bar')).($isTop ? ' · '.e(__('Matters')).' · '.e(__('Fees')) : '').'</div>'
            .'<div style="display:flex;min-height:9rem;">'
            .($isTop ? '' : '<div style="width:7rem;padding:.6rem;background:'.e($sidebar).';color:'.e($ink($sidebar)).';">'.e(__('Matters')).'<br>'.e(__('Fees')).'<br>'.e(__('Settings')).'</div>')
            .'<div style="flex:1;padding:.8rem;">'
            .'<div style="padding:.8rem;border-radius:'.$corner.';background:'.e($surface('cards', '#ffffff')).';color:'.e($surface('text', '#111827')).';box-shadow:0 1px 2px rgb(0 0 0 / .08);">'
            .$button($primary, __('Save'))
            .$button($color('secondary', '#d97706'), __('Secondary'))
            .$button($color('danger', '#dc2626'), __('Delete'))
            .'<div style="margin-top:.4rem;">'
            .$badge($color('success', '#16a34a'), __('Paid'))
            .$badge($color('warning', '#d97706'), __('Pending'))
            .$badge($color('info', '#0284c7'), __('New'))
            .$badge($color('gray', '#6b7280'), __('Draft'))
            .'</div></div></div></div></div>'
            .($hardToRead ? '<p style="color:#b45309;margin-top:.5rem;">'.e(__('White text is hard to read on this primary colour — choose a darker one.')).'</p>' : '')
        );
    }
}
