<?php

namespace App\Filament\Mms\Resources\LetterFonts;

use App\Filament\Concerns\HasModuleGate;
use App\Filament\Mms\Clusters\Templates;
use App\Filament\Mms\Resources\LetterFonts\Pages\ManageLetterFonts;
use App\Models\LetterFont;
use App\Models\Setting;
use App\Services\MMS\Letters\LetterDocx;
use App\Support\InterfaceFont;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Fonts for letters and minutes, uploaded by the office: a licensed font
 * (Calibri, from Windows' Fonts folder) can't come with the system.
 */
class LetterFontResource extends Resource
{
    use HasModuleGate;

    protected static ?string $model = LetterFont::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLanguage;

    protected static ?int $navigationSort = 5;

    protected static ?string $cluster = Templates::class;

    public static function moduleGateKey(): string
    {
        return 'mms_communications';
    }

    public static function getModelLabel(): string
    {
        return __('Font');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Fonts');
    }

    public static function form(Schema $schema): Schema
    {
        $file = fn (string $weight, string $label) => FileUpload::make('files.'.$weight)
            ->label($label)
            ->disk(LetterFont::DISK)
            ->directory(LetterFont::DIRECTORY)
            ->preserveFilenames()
            // A font's type is reported differently by each system.
            ->acceptedFileTypes(['font/ttf', 'font/sfnt', 'application/x-font-ttf', 'application/x-font-truetype', 'application/font-sfnt', 'application/octet-stream'])
            ->maxSize(20480)
            // The PDF can draw TrueType outlines only: an OpenType font with
            // PostScript (CFF) outlines would stop it.
            ->rules([fn () => function (string $attribute, mixed $file, Closure $fail): void {
                $head = is_object($file) && method_exists($file, 'getRealPath') ? (string) @file_get_contents($file->getRealPath(), false, null, 0, 4) : '';
                if ($head === 'OTTO') {
                    $fail(__('This font has PostScript outlines, which the PDF cannot use. Upload its TrueType (.ttf) version.'));
                }
            }]);

        return $schema->columns(2)->components([
            TextInput::make('name')
                ->label(__('Name'))
                ->helperText(__('As Word names it, e.g. Calibri or Calibri Light — Word documents ask for it by this name.'))
                ->required()
                ->maxLength(100)
                ->columnSpanFull(),
            $file('regular', __('Regular'))
                ->required()
                ->helperText(__('A .ttf file — for Calibri, calibri.ttf from C:\Windows\Fonts.')),
            $file('bold', __('Bold'))->helperText('calibrib.ttf'),
            $file('italic', __('Italic'))->helperText('calibrii.ttf'),
            $file('bold_italic', __('Bold italic'))->helperText('calibriz.ttf'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label(__('Name'))->weight('bold')->searchable(),
                TextColumn::make('weights')
                    ->label(__('Weights'))
                    ->state(fn (LetterFont $record) => collect(LetterFont::WEIGHTS)
                        ->keys()
                        ->filter(fn (string $weight) => filled(($record->files ?? [])[$weight] ?? null))
                        ->map(fn (string $weight) => __(str($weight)->replace('_', ' ')->ucfirst()->toString()))
                        ->implode(' · ')),
                TextColumn::make('system')
                    ->label(__('System font'))
                    ->state(fn (LetterFont $record) => collect([
                        InterfaceFont::current()?->is($record) ? __('English text') : null,
                        InterfaceFont::arabic()?->is($record) ? __('Arabic text') : null,
                    ])->filter()->implode(' · '))
                    ->placeholder('—')
                    ->badge(),
            ])
            ->recordActions([
                // Every screen of the system in it, for everyone — each letter
                // in the font for its script, as Boutros.css does.
                self::useFor('useForEnglish', InterfaceFont::SETTING, __('Use for English text'), fn () => InterfaceFont::current()),
                self::useFor('useForArabic', InterfaceFont::ARABIC_SETTING, __('Use for Arabic text'), fn () => InterfaceFont::arabic()),
                EditAction::make()->modalWidth('3xl'),
                DeleteAction::make()
                    ->after(function (LetterFont $record): void {
                        foreach ([InterfaceFont::SETTING, InterfaceFont::ARABIC_SETTING] as $setting) {
                            if ((int) Setting::get($setting) === (int) $record->getKey()) {
                                Setting::set($setting, null, 'appearance');
                            }
                        }
                    }),
            ])
            ->headerActions([
                // Word documents: one font, set here — not the templates'.
                Action::make('wordFont')
                    ->label(__('Word documents font'))
                    ->icon(Heroicon::OutlinedDocumentText)
                    ->color('gray')
                    ->fillForm(fn (): array => ['font' => LetterDocx::wordFont() === LetterDocx::FONT ? 'standard' : 'calibri'])
                    ->schema([
                        Radio::make('font')
                            ->hiddenLabel()
                            ->options([
                                'calibri' => __('Calibri — on every Windows computer, Arabic included'),
                                'standard' => __('The system\'s standard font'),
                            ])
                            ->required(),
                    ])
                    ->action(function (array $data): void {
                        Setting::set(LetterDocx::WORD_FONT, $data['font'] === 'standard' ? 'standard' : 'calibri', 'appearance');
                        Notification::make()->success()->title(__('Saved'))->send();
                    }),
                Action::make('standardSystemFont')
                    ->label(__('Back to the standard font'))
                    ->icon(Heroicon::OutlinedArrowUturnLeft)
                    ->color('gray')
                    ->visible(fn () => InterfaceFont::current() !== null || InterfaceFont::arabic() !== null)
                    ->requiresConfirmation()
                    ->action(function (): void {
                        Setting::set(InterfaceFont::SETTING, null, 'appearance');
                        Setting::set(InterfaceFont::ARABIC_SETTING, null, 'appearance');
                        Notification::make()->success()->title(__('The system is shown in the standard font again. Refresh the page to see it.'))->send();
                    }),
            ]);
    }

    /**
     * @param  Closure(): ?LetterFont  $current
     */
    private static function useFor(string $name, string $setting, string $label, Closure $current): Action
    {
        return Action::make($name)
            ->label($label)
            ->icon(Heroicon::OutlinedComputerDesktop)
            ->requiresConfirmation()
            ->modalDescription(__('Every screen of the system shows this text in this font, for everyone. Windows uses its own copy; other devices load the uploaded files.'))
            ->visible(fn (LetterFont $record) => ! ($current()?->is($record) ?? false))
            ->action(function (LetterFont $record) use ($setting): void {
                Setting::set($setting, $record->getKey(), 'appearance');
                Notification::make()->success()->title(__('Saved. Refresh the page to see it.'))->send();
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageLetterFonts::route('/'),
        ];
    }
}
