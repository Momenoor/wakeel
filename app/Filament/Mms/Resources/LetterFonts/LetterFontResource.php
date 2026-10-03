<?php

namespace App\Filament\Mms\Resources\LetterFonts;

use App\Filament\Concerns\HasModuleGate;
use App\Filament\Mms\Resources\LetterFonts\Pages\ManageLetterFonts;
use App\Models\LetterFont;
use App\Models\Setting;
use App\Support\InterfaceFont;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
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

    protected static ?int $navigationSort = 9;

    public static function moduleGateKey(): string
    {
        return 'mms_communications';
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Communication');
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
            ->maxSize(20480);

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
                IconColumn::make('system')
                    ->label(__('System font'))
                    ->state(fn (LetterFont $record) => InterfaceFont::current()?->is($record) ?? false)
                    ->boolean()
                    ->falseIcon(null),
            ])
            ->recordActions([
                // Every screen of the system in it, for everyone.
                Action::make('useForSystem')
                    ->label(__('Use for the system'))
                    ->icon(Heroicon::OutlinedComputerDesktop)
                    ->requiresConfirmation()
                    ->modalDescription(__('Every screen of the system is shown in this font, for everyone. Windows uses its own copy; other devices load the uploaded files.'))
                    ->visible(fn (LetterFont $record) => ! (InterfaceFont::current()?->is($record) ?? false))
                    ->action(function (LetterFont $record): void {
                        Setting::set(InterfaceFont::SETTING, $record->getKey(), 'appearance');
                        Notification::make()->success()->title(__('The system is now shown in :font. Refresh the page to see it.', ['font' => $record->name]))->send();
                    }),
                EditAction::make()->modalWidth('3xl'),
                DeleteAction::make()
                    ->after(function (LetterFont $record): void {
                        if ((int) Setting::get(InterfaceFont::SETTING) === (int) $record->getKey()) {
                            Setting::set(InterfaceFont::SETTING, null, 'appearance');
                        }
                    }),
            ])
            ->headerActions([
                Action::make('standardSystemFont')
                    ->label(__('Back to the standard font'))
                    ->icon(Heroicon::OutlinedArrowUturnLeft)
                    ->color('gray')
                    ->visible(fn () => InterfaceFont::current() !== null)
                    ->requiresConfirmation()
                    ->action(function (): void {
                        Setting::set(InterfaceFont::SETTING, null, 'appearance');
                        Notification::make()->success()->title(__('The system is shown in the standard font again. Refresh the page to see it.'))->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageLetterFonts::route('/'),
        ];
    }
}
