<?php

namespace App\Filament\Mms\Pages;

use App\Filament\Mms\Pages\Concerns\ReviewsOneDriveFolders;
use App\Filament\Mms\Resources\Types\Schemas\TypeForm;
use App\Filament\Shared\Clusters\Settings;
use App\Models\Setting;
use App\Models\Type;
use App\Services\MMS\MatterOneDriveFolders;
use App\Services\MMS\OneDriveClient;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Support\HtmlString;
use Throwable;

/**
 * Matter folders in the assistants' OneDrive: on or off, and the standard
 * subfolders each new matter folder gets. Changing the list affects only
 * folders made afterwards. The active matters' existing folders are
 * reviewed below, and standardised only when the office decides so.
 */
class OneDriveSettings extends Page implements HasTable
{
    use InteractsWithTable;
    use ReviewsOneDriveFolders {
        ReviewsOneDriveFolders::table insteadof InteractsWithTable;
    }

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCloud;

    protected static ?int $navigationSort = 13;

    protected static ?string $cluster = Settings::class;

    public ?array $data = [];

    /**
     * The last test run, one row per assistant.
     *
     * @var list<array{name: string, email: string, ok: bool, message: string, url: ?string}>
     */
    public array $testResults = [];

    public static function getNavigationLabel(): string
    {
        return __('OneDrive Folders');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('General');
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
            'signed_minutes' => MatterOneDriveFolders::signedMinutesFolder(),
            'sent_emails' => MatterOneDriveFolders::sentEmailsFolder(),
            'received_emails' => MatterOneDriveFolders::receivedEmailsFolder(),
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
                            ->helperText(__('One per line, in order. Use "/" for a folder inside another, e.g. "02 المستندات/من المدعي". Changes apply to folders made from now on; existing folders stay as they are.').' '.__('The default — a matter type can have its own (below, or on the type).'))
                            ->rows(10)
                            ->extraInputAttributes(['dir' => 'auto']),
                        Placeholder::make('type_structures')
                            ->label(__('Matter types with their own structure'))
                            ->content(fn (): string => Type::query()->whereNotNull('onedrive_subfolders')->where('onedrive_subfolders', '!=', '')->orderBy('name')->pluck('name')->implode('، ') ?: __('None — every type uses the structure above.'))
                            ->hintAction(
                                Action::make('assignToTypes')
                                    ->label(__('Assign a structure to matter types'))
                                    ->icon(Heroicon::OutlinedFolder)
                                    ->fillForm(fn (Get $get): array => ['structure' => $get('subfolders')])
                                    ->schema([
                                        Select::make('types')
                                            ->label(__('Matter types'))
                                            ->options(fn () => Type::query()->orderBy('name')->pluck('name', 'id'))
                                            ->multiple()
                                            ->searchable()
                                            ->required(),
                                        Toggle::make('use_default')
                                            ->label(__('Use the default structure'))
                                            ->live(),
                                        TypeForm::oneDriveStructureField('structure')
                                            ->hidden(fn (Get $get): bool => (bool) $get('use_default'))
                                            ->required(fn (Get $get): bool => ! $get('use_default')),
                                    ])
                                    ->action(function (array $data): void {
                                        $structure = ! empty($data['use_default']) ? null : (trim((string) ($data['structure'] ?? '')) ?: null);
                                        Type::query()->whereIn('id', $data['types'] ?? [])->update(['onedrive_subfolders' => $structure]);

                                        Notification::make()->success()->title(__('Folder structure set for :count types', ['count' => count($data['types'] ?? [])]))->send();
                                    }),
                            ),
                        TextInput::make('signed_minutes')
                            ->label(__('Signed minutes subfolder'))
                            ->helperText(__('Signed minutes sent back on WhatsApp are saved here, inside the matter\'s folder (made when missing). Use "/" for a folder inside another.'))
                            ->required()
                            ->extraInputAttributes(['dir' => 'auto']),
                        TextInput::make('sent_emails')
                            ->label(__('Sent emails subfolder'))
                            ->helperText(__('Every email sent from a matter — letters, minutes, bulk mail — is saved here as a PDF, inside the matter\'s folder (made when missing). Leave empty not to save them in OneDrive.'))
                            ->extraInputAttributes(['dir' => 'auto']),
                        TextInput::make('received_emails')
                            ->label(__('Replies subfolder'))
                            ->helperText(__('Replies to those emails, collected from the mailbox, are saved here as a PDF with every file they came with. Leave empty not to save them in OneDrive.'))
                            ->extraInputAttributes(['dir' => 'auto']),
                    ]),

                Section::make(__('Test folder'))
                    ->description(__('Checks the whole set-up: makes a folder named ":name", with the standard subfolders, in every assistant\'s OneDrive, then removes it again (to their OneDrive recycle bin). Save the subfolder list first.', ['name' => MatterOneDriveFolders::TEST_FOLDER]))
                    ->icon(Heroicon::OutlinedBeaker)
                    ->schema([
                        Actions::make([
                            Action::make('createTestFolder')
                                ->label(__('Create test folder'))
                                ->icon(Heroicon::OutlinedFolderPlus)
                                ->color('primary')
                                ->requiresConfirmation()
                                ->modalDescription(fn (): string => __('Makes the test folder in the OneDrive of :count assistant(s) with a OneDrive account on their profile.', ['count' => MatterOneDriveFolders::assistantsWithOneDrive()->count()]))
                                ->action(fn () => $this->runTest('create')),
                            Action::make('removeTestFolder')
                                ->label(__('Remove test folder'))
                                ->icon(Heroicon::OutlinedTrash)
                                ->color('danger')
                                ->requiresConfirmation()
                                ->modalDescription(__('Removes the test folder from every assistant\'s OneDrive. It goes to their OneDrive recycle bin. Nothing else is touched.'))
                                ->action(fn () => $this->runTest('remove')),
                        ]),
                        Placeholder::make('test_results')
                            ->hiddenLabel()
                            ->visible(fn (): bool => $this->testResults !== [])
                            ->content(fn (): HtmlString => new HtmlString(view('filament.mms.onedrive-test-results', ['rows' => $this->testResults])->render())),
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

            // The active matters' existing folders: found by number and
            // year, standardised one decision at a time (ReviewsOneDriveFolders).
            Section::make(__("Existing matters' folders"))
                ->description(__("Active matters' folders in the assistants' OneDrive, found by matter number and year. Decide one by one: Apply renames the folder and its subfolders to the standard and links it to the matter (or makes it, when none was found); Skip leaves it as it is. Nothing is deleted or moved."))
                ->icon(Heroicon::OutlinedFolderOpen)
                ->headerActions([$this->scanAction()])
                ->schema([EmbeddedTable::make()]),
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
        Setting::set(MatterOneDriveFolders::SIGNED_MINUTES, trim((string) ($state['signed_minutes'] ?? '')), 'onedrive');
        Setting::set(MatterOneDriveFolders::SENT_EMAILS, trim((string) ($state['sent_emails'] ?? '')), 'onedrive');
        Setting::set(MatterOneDriveFolders::RECEIVED_EMAILS, trim((string) ($state['received_emails'] ?? '')), 'onedrive');

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

    /**
     * Creates or removes the test folder in each assistant's OneDrive, one
     * at a time, recording how each went — one failing never stops the rest.
     */
    public function runTest(string $what): void
    {
        if (! in_array($what, ['create', 'remove'], true)) {
            return;
        }

        @set_time_limit(300);

        $folders = app(MatterOneDriveFolders::class);
        $rows = [];

        foreach (MatterOneDriveFolders::assistantsWithOneDrive() as $party) {
            $row = ['name' => $party->name, 'email' => (string) $party->onedrive_email, 'ok' => true, 'message' => '', 'url' => null];

            try {
                if ($what === 'create') {
                    $item = $folders->createTestFolder($party);
                    $row['message'] = __('Created');
                    $row['url'] = $item['webUrl'] ?: null;
                } else {
                    $row['message'] = $folders->removeTestFolder($party) ? __('Removed') : __('Not there — nothing to remove');
                }
            } catch (Throwable $exception) {
                $row['ok'] = false;
                $row['message'] = $exception->getMessage();
            }

            $rows[] = $row;
        }

        $this->testResults = $rows;

        $failed = collect($rows)->where('ok', false)->count();

        Notification::make()
            ->title(match (true) {
                $rows === [] => __('No assistant has a OneDrive account on their profile yet.'),
                $failed === 0 => $what === 'create' ? __('Test folder created for every assistant.') : __('Test folder removed for every assistant.'),
                default => __(':failed of :total failed — see the table.', ['failed' => $failed, 'total' => count($rows)]),
            })
            ->status($rows === [] ? 'warning' : ($failed === 0 ? 'success' : 'danger'))
            ->send();
    }
}
