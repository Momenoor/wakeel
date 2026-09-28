<?php

namespace App\Filament\Mms\Pages;

use App\Models\Setting;
use App\Services\MMS\MatterOneDriveFolders;
use App\Services\MMS\OneDriveClient;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Throwable;
use UnitEnum;

/**
 * Matter folders in the assistants' OneDrive: on or off, and the standard
 * subfolders each new matter folder gets. Changing the list affects only
 * folders made afterwards — existing ones are never touched.
 */
class OneDriveSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCloud;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 8;

    public ?array $data = [];

    public static function getNavigationLabel(): string
    {
        return __('OneDrive Folders');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Settings');
    }

    public function getTitle(): string
    {
        return __('OneDrive Folders');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can('View:OneDriveSettings') ?? false;
    }

    public function mount(): void
    {
        $this->form->fill([
            'enabled' => MatterOneDriveFolders::enabled(),
            'subfolders' => Setting::get(MatterOneDriveFolders::SUBFOLDERS, ''),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make(__('Matter folders'))
                    ->description(__('When an assistant is assigned to a new matter, a folder named like "2026-123 - matter type - court" is made in their OneDrive, under the path on their profile, with the subfolders below.'))
                    ->icon(Heroicon::OutlinedFolderPlus)
                    ->schema([
                        Toggle::make('enabled')
                            ->label(__('Create OneDrive folders for new matters')),
                        Placeholder::make('since')
                            ->label(__('Matters created from'))
                            ->content(fn (): string => MatterOneDriveFolders::enabledAt()?->translatedFormat('d M Y, g:i A') ?? __('Not switched on yet'))
                            ->helperText(__('Matters created before the feature was switched on are not touched.')),
                        Textarea::make('subfolders')
                            ->label(__('Standard subfolders'))
                            ->helperText(__('One per line, in order. Use "/" for a folder inside another, e.g. "02 المستندات/من المدعي". Changes apply to folders made from now on; existing folders stay as they are.'))
                            ->rows(10)
                            ->extraInputAttributes(['dir' => 'auto']),
                    ]),

                Section::make(__('Connection'))
                    ->description(__('Uses the Microsoft 365 app registration in .env (the Outlook calendar one, otherwise the mail one), which needs the Files.ReadWrite.All application permission with admin consent.'))
                    ->icon(Heroicon::OutlinedSignal)
                    ->schema([
                        TextInput::make('test_email')
                            ->label(__('Test with an assistant\'s Microsoft 365 email'))
                            ->email()
                            ->dehydrated(false)
                            ->suffixAction(
                                Action::make('test')
                                    ->label(__('Test connection'))
                                    ->icon(Heroicon::OutlinedSignal)
                                    ->action(fn (Get $get) => $this->testConnection((string) $get('test_email'))),
                            ),
                    ]),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([
                EmbeddedSchema::make('form'),
            ])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([
                    Actions::make([
                        Action::make('save')
                            ->label(__('Save Settings'))
                            ->submit('save')
                            ->icon(Heroicon::Check)
                            ->color('primary')
                            ->keyBindings(['mod+s']),
                    ])->key('form-actions'),
                ]),
        ]);
    }

    public function save(): void
    {
        $state = $this->form->getState();
        $enable = (bool) ($state['enabled'] ?? false);

        // Switching on (again) starts from now: matters created while it
        // was off do not get folders later.
        if ($enable && ! MatterOneDriveFolders::enabled()) {
            Setting::set(MatterOneDriveFolders::ENABLED_AT, now()->toDateTimeString(), 'onedrive');
        }

        Setting::set(MatterOneDriveFolders::ENABLED, $enable, 'onedrive');
        Setting::set(MatterOneDriveFolders::SUBFOLDERS, trim((string) ($state['subfolders'] ?? '')), 'onedrive');

        Notification::make()->title(__('Settings saved successfully'))->success()->send();
    }

    public function testConnection(string $email): void
    {
        if (blank($email)) {
            Notification::make()->title(__('Enter an email to test with.'))->warning()->send();

            return;
        }

        try {
            $url = app(OneDriveClient::class)->test($email);
        } catch (Throwable $exception) {
            Notification::make()->title(__('Could not reach OneDrive'))->body($exception->getMessage())->danger()->persistent()->send();

            return;
        }

        Notification::make()->title(__('Connected to OneDrive'))->body($url)->success()->send();
    }
}
