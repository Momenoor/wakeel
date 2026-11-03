<?php

namespace App\Filament\Mms\Pages;

use App\Filament\Mms\Pages\Concerns\ReviewsOneDriveFolders;
use App\Filament\Mms\Resources\Types\Schemas\TypeForm;
use App\Filament\Shared\Clusters\Settings;
use App\Filament\Support\EntryTable;
use App\Filament\Support\SettingsForm;
use App\Jobs\StandardiseAssistantFolders;
use App\Models\Matter;
use App\Models\MatterFieldDefinition;
use App\Models\Party;
use App\Models\Setting;
use App\Models\Type;
use App\Services\MMS\MatterOneDriveFolders;
use App\Services\MMS\OneDriveClient;
use App\Services\MMS\PeriodicReports;
use App\Services\MMS\ReceivedEmailFiling;
use App\Support\Dates;
use App\Support\MatterSearch;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Grid;
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

    protected static ?int $navigationSort = 41;

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
        return __('Integrations');
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
            'shortcuts' => MatterOneDriveFolders::shortcuts(),
            'folder_check' => (bool) Setting::get(MatterOneDriveFolders::FOLDER_CHECK, false),
            'subfolders' => Setting::get(MatterOneDriveFolders::SUBFOLDERS, ''),
            'matters_folder' => Setting::get(MatterOneDriveFolders::MATTERS_FOLDER, ''),
            'finished_folder' => Setting::get(MatterOneDriveFolders::FINISHED_FOLDER, ''),
            'signed_minutes' => MatterOneDriveFolders::signedMinutesFolder(),
            'sent_emails' => MatterOneDriveFolders::sentEmailsFolder(),
            MatterOneDriveFolders::SENT_TO_CREDITORS => Setting::get(MatterOneDriveFolders::SENT_TO_CREDITORS, ''),
            'received_emails' => MatterOneDriveFolders::receivedEmailsFolder(),
            // Each side's folder and name, and the general name (ReceivedEmailFiling), and a periodic report's
            // folder: the settings by their keys.
            ...collect(self::keyedSettings())->mapWithKeys(fn (string $key): array => [$key => Setting::get($key, '')])->all(),
        ]);
    }

    /**
     * The settings kept by their keys: of the emails that come in from each side and of their names
     * (ReceivedEmailFiling), and of a periodic report's folder (PeriodicReports).
     *
     * @return list<string>
     */
    private static function keyedSettings(): array
    {
        return [...array_values(ReceivedEmailFiling::FOLDER_SETTINGS), ...array_values(ReceivedEmailFiling::ATTACHMENTS_SETTINGS), ...array_values(ReceivedEmailFiling::NAME_SETTINGS), PeriodicReports::SETTING];
    }

    /** The groups of types alike in their own folders: who is in each, and what their folders are. */
    private static function typeGroups(): HtmlString
    {
        $groups = Type::folderGroups();
        if ($groups->isEmpty()) {
            return new HtmlString(e(__('None — every type uses the folders above.')));
        }

        $labels = [
            'onedrive_subfolders' => __('Standard subfolders'),
            'phase_keep_folders' => __('Folders kept for the next phase'),
            'onedrive_received_emails' => __('Received emails folder structure'),
            'onedrive_received_plaintiff' => __('Emails from the plaintiff side — folder'),
            'onedrive_received_defendant' => __('Emails from the defendant side — folder'),
            'received_name' => __('Received emails — file name'),
            'received_name_plaintiff' => __('Emails from the plaintiff side — file name'),
            'received_name_defendant' => __('Emails from the defendant side — file name'),
            'onedrive_received_creditors' => __('Emails from creditors — folder'),
            'onedrive_attachments' => __('Files received emails came with — folder'),
            'onedrive_attachments_plaintiff' => __('Files from the plaintiff side — folder'),
            'onedrive_attachments_defendant' => __('Files from the defendant side — folder'),
            'onedrive_attachments_creditors' => __('Files from creditors — folder'),
            'received_name_creditor' => __('Emails from creditors — file name'),
            'onedrive_sent_emails' => __('Sent emails subfolder'),
            'onedrive_sent_creditors' => __('Emails sent to creditors — folder'),
            'onedrive_signed_minutes' => __('Signed minutes subfolder'),
            'onedrive_periodic_reports' => __('Periodic report folder'),
        ];

        return new HtmlString($groups->values()->map(function ($types, int $i) use ($labels): string {
            $first = $types->first();
            $folders = collect(Type::FOLDER_FIELDS)
                ->map(fn (string $field): string => '<li>'.e($labels[$field]).': <span dir="auto">'.(filled($first->{$field})
                    ? e(str_replace("\n", ' · ', trim((string) $first->{$field})))
                    : e(__('the default'))).'</span></li>')
                ->implode('');

            return '<div style="margin-bottom: .75rem;"><strong>'.e(__('Group :n', ['n' => $i + 1])).':</strong> '.e($types->pluck('name')->implode('، '))
                .'<ul style="list-style: disc; padding-inline-start: 1.1rem; margin: .25rem 0 0;">'.$folders.'</ul></div>';
        })->implode(''));
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make(__('Matter folders'))
                    ->description(__('When an assistant is assigned to a new matter, a folder named like "2026-123 - matter type - court" is made in their OneDrive, under the path on their profile, with the subfolders below.').' '.__('Another phase of a case — a matter with the same year and number, or the same parent matter — goes in that case\'s main folder, in a subfolder of its own named by the structure (e.g. {{matter.custom.Phase}}). When a phase is finished, only its subfolder moves to the assistant\'s folder for finished matters, into a main folder of the same name.'))
                    ->icon(Heroicon::OutlinedFolderPlus)
                    ->schema([
                        Toggle::make('enabled')
                            ->label(__('Create OneDrive folders for new matters')),
                        Toggle::make('shortcuts')
                            ->label(__('The other assistants get a shortcut, not a folder of their own'))
                            ->helperText(__('A matter with several assistants: the folder is made in the first assistant\'s OneDrive only, and each of the others — one added later too — is given access to it and a shortcut to it under their matters folder. When the matter is finished, the folder moves with its owner and the shortcut is made anew under their finished matters. Off, each assistant gets a full folder of their own.')),
                        Toggle::make('folder_check')
                            ->label(__('Look for the folders the system made and tell of one missing'))
                            ->helperText(__('Once an hour the folders the system made in the matters in progress are looked for — the standard subfolders and the folders emails were filed in — and one renamed, moved or deleted by hand is told to the matter\'s people and the super administrators in the bell, once a day. Off, nothing is looked for and nobody is told.')),
                        Placeholder::make('since')
                            ->label(__('Matters created from'))
                            ->content(fn (): string => MatterOneDriveFolders::enabledAt()?->format(Dates::dateTimeFormat()) ?? __('Not switched on yet'))
                            ->helperText(__('Matters created before the feature was switched on are not touched.')),
                        // The same two folders at the top of every assistant's OneDrive.
                        TextInput::make('matters_folder')
                            ->label(__('Standard folder for matters in progress'))
                            ->placeholder(MatterOneDriveFolders::standardFolders()['matters'])
                            ->helperText(__('The name of the folder at the top of each assistant\'s OneDrive that holds their matters\' folders — the same for everyone, so no one renames theirs. Set on each assistant\'s profile by "Apply to the assistants".'))
                            ->maxLength(255)
                            ->extraInputAttributes(['dir' => 'auto']),
                        TextInput::make('finished_folder')
                            ->label(__('Standard folder for finished matters'))
                            ->placeholder(MatterOneDriveFolders::standardFolders()['finished'])
                            ->helperText(__('The folder a finished matter\'s folder is moved to, at the top of each assistant\'s OneDrive.'))
                            ->maxLength(255)
                            ->extraInputAttributes(['dir' => 'auto']),
                        Actions::make([
                            Action::make('applyStandardFolders')
                                ->label(__('Apply to the assistants'))
                                ->icon(Heroicon::OutlinedFolderArrowDown)
                                ->color('warning')
                                ->requiresConfirmation()
                                ->modalHeading(__('Give every assistant\'s folders the standard names'))
                                ->modalDescription(__('For each assistant with a OneDrive account: what their folder for matters holds now is moved under the standard name at the top of their OneDrive (into a folder of that name there already, when there is one), the same for their finished matters, the paths on their profile are set to the names, and the matters\' links follow. Done one assistant at a time in the background; you are told in the bell. Save the settings first.'))
                                ->action(fn () => $this->applyStandardFolders()),
                        ])->columnSpanFull(),
                    ])
                    ->columns(['default' => 1, 'lg' => 2]),

                // The folders and emails, group by group, two by two.
                Grid::make(['default' => 1, 'lg' => 2])->schema([
                    Section::make(__('OneDrive folder structure'))
                        ->icon(Heroicon::OutlinedFolder)
                        ->schema([
                            Textarea::make('subfolders')
                                ->label(__('Standard subfolders'))
                                ->helperText(__('One per line, in order. Use "/" for a folder inside another, e.g. "02 المستندات/من المدعي". Changes apply to folders made from now on; existing folders stay as they are.').' '.__('The default — a matter type can have its own (below, or on the type).').' '.__('A folder may use the matter\'s placeholders, like the letters do, e.g. {{matter.custom.phase}}/01 المراسلات; one the matter has no value for is left out.'))
                                ->rows(10)
                                ->extraInputAttributes(['dir' => 'auto']),
                            // The types with folders of their own, in groups of those alike — one read: a group's are
                            // changed together from any of its types (Settings → Matter types → its folders).
                            Placeholder::make('type_structures')
                                ->label(__('Matter types with folders of their own'))
                                ->content(fn (): HtmlString => self::typeGroups())
                                ->hintAction(
                                    // Every folder setting of a type (Type::FOLDER_FIELDS) given to many types at once: copied from a type
                                    // set up already, the standard subfolders alone, or cleared so the defaults on this page apply.
                                    Action::make('assignToTypes')
                                        ->label(__('Assign a structure to matter types'))
                                        ->icon(Heroicon::OutlinedFolder)
                                        ->modalWidth('2xl')
                                        ->fillForm(fn (Get $get): array => ['structure' => $get('subfolders'), 'source' => 'type'])
                                        ->schema([
                                            Select::make('types')
                                                ->label(__('Matter types'))
                                                ->options(fn () => Type::query()->orderBy('name')->pluck('name', 'id'))
                                                ->multiple()
                                                ->searchable()
                                                ->required(),
                                            Radio::make('source')
                                                ->label(__('Give them'))
                                                ->options([
                                                    'type' => __('Every folder setting of a matter type set up already (its subfolders, emails, files, names, sent emails, signed minutes, periodic reports)'),
                                                    'structure' => __('The standard subfolders below only'),
                                                    'default' => __('No folders of their own: the defaults on this page apply to them'),
                                                ])
                                                ->default('type')
                                                ->live(),
                                            Select::make('from_type')
                                                ->label(__('Copy from'))
                                                ->options(fn () => Type::query()->orderBy('name')->pluck('name', 'id'))
                                                ->searchable()
                                                ->visible(fn (Get $get): bool => $get('source') === 'type')
                                                ->required(fn (Get $get): bool => $get('source') === 'type'),
                                            TypeForm::oneDriveStructureField('structure')
                                                ->visible(fn (Get $get): bool => $get('source') === 'structure')
                                                ->required(fn (Get $get): bool => $get('source') === 'structure'),
                                        ])
                                        ->action(function (array $data): void {
                                            $types = Type::query()->whereIn('id', $data['types'] ?? []);

                                            $types->update(match ($data['source'] ?? 'structure') {
                                                'type' => Type::query()->findOrFail($data['from_type'])->only(Type::FOLDER_FIELDS),
                                                'default' => array_fill_keys(Type::FOLDER_FIELDS, null),
                                                default => ['onedrive_subfolders' => trim((string) ($data['structure'] ?? '')) ?: null],
                                            });

                                            Notification::make()->success()->title(__('Folder structure set for :count types', ['count' => count($data['types'] ?? [])]))->send();
                                        }),
                                ),
                        ]),
                    Section::make(__('Sent emails and signed minutes'))
                        ->icon(Heroicon::OutlinedPaperAirplane)
                        ->schema([
                            TextInput::make('signed_minutes')
                                ->label(__('Signed minutes subfolder'))
                                ->helperText(__('Signed minutes sent back on WhatsApp are saved here, inside the matter\'s folder (made when missing). Use "/" for a folder inside another.').' '.__('A folder may use the matter\'s placeholders, like the letters do; {{day}} is the day it is saved.'))
                                ->required()
                                ->extraInputAttributes(['dir' => 'auto']),
                            TextInput::make('sent_emails')
                                ->label(__('Sent emails subfolder'))
                                ->helperText(__('Every email sent from a matter — letters, minutes, bulk mail — is saved here as a PDF, inside the matter\'s folder (made when missing). Leave empty not to save them in OneDrive.').' '.__('A folder may use the matter\'s placeholders, like the letters do; {{day}} is the day it is sent.'))
                                ->extraInputAttributes(['dir' => 'auto']),
                            TypeForm::sentToCreditorsField(forType: false),
                            TypeForm::periodicReportsField(PeriodicReports::SETTING, forType: false),
                        ]),
                    Section::make(__('Received emails — folders'))
                        ->icon(Heroicon::OutlinedInboxArrowDown)
                        ->schema([
                            TextInput::make('received_emails')
                                ->label(__('Received emails folder structure'))
                                ->helperText(new HtmlString(
                                    e(__('Where the emails that come in — replies and any other email from a matter\'s parties — are saved as a PDF with every file they came with, inside the matter\'s folder (made when missing). Folders are separated by "/", and each may use the matter\'s placeholders, like the letters do. Leave empty not to save them in OneDrive.'))
                                    .'<br><code dir="ltr">{{matter.phase}}/01 system + 02/{{matter.plaintiff_capacity}}</code>'
                                    .'<br>'.e(__('{{matter.phase}} is the matter\'s phase, for a type with phases; a custom field is written {{matter.custom.<its name>}} — pick from the list below.'))
                                    .'<br>'.e(__('A part with no value for the matter (e.g. a custom field it has none of) is left out.'))
                                    .'<br>'.e(__('{{day}} is the day the email reached us (2026-10-16), {{month}} the month and {{year}} the year: the emails of one day go in the same folder.'))
                                ))
                                ->live(debounce: 500)
                                ->extraInputAttributes(['dir' => 'ltr']),
                            // The emails from each side apart, each named as set; a matter type may have its own.
                            ...array_map(fn (string $side): TextInput => TypeForm::receivedSideFolderField($side, forType: false), ReceivedEmailFiling::APART),
                            // The files the emails come with, apart from the emails: in general, and a side's.
                            ...array_map(fn (string $side): TextInput => TypeForm::attachmentsFolderField($side, forType: false), [ReceivedEmailFiling::GENERAL, ...ReceivedEmailFiling::APART]),
                        ]),
                    Section::make(__('Received emails — file names'))
                        ->icon(Heroicon::OutlinedDocumentText)
                        ->schema([
                            ...array_map(fn (string $side): TextInput => TypeForm::receivedNameField($side, forType: false), [ReceivedEmailFiling::GENERAL, ...ReceivedEmailFiling::APART]),
                        ]),
                    Section::make(__('Placeholders'))
                        ->icon(Heroicon::OutlinedVariable)
                        ->columnSpanFull()
                        ->schema([
                            // The custom fields that exist, ready to use: the placeholder is the field's name as it is on the type.
                            Placeholder::make('custom_field_placeholders')
                                ->label(__('The phase and the custom fields you can use'))
                                ->helperText(__('A placeholder cut short: {{matter.plaintiffs,c:30}} its first 30 characters, {{matter.plaintiffs,w:3}} its first 3 words — in folders and letters alike.'))
                                ->content(function (): HtmlString {
                                    // Its phase first; then the custom fields there are.
                                    $labels = MatterFieldDefinition::query()->orderBy('label')->pluck('label')->unique()->values();

                                    return new HtmlString('<code dir="ltr" style="margin-inline-end: .75rem;">{{matter.phase}}</code> '.($labels->isEmpty()
                                        ? e(__('No custom fields yet (Settings → Matter types).'))
                                        : $labels->map(fn (string $label): string => '<code dir="auto" style="margin-inline-end: .75rem;">{{matter.custom.'.e($label).'}}</code>')->implode(' ')));
                                }),
                            // Not saved: only to see what the structure above makes for a matter.
                            Select::make('received_preview_matter')
                                ->label(__('Try it on a matter'))
                                ->searchable()
                                ->live()
                                ->dehydrated(false)
                                ->getSearchResultsUsing(fn (string $search): array => MatterSearch::options($search))
                                ->getOptionLabelUsing(fn ($value): ?string => MatterSearch::labels([(int) $value])[(int) $value] ?? null),
                            Placeholder::make('received_preview')
                                ->label(__('The folder for that matter'))
                                ->visible(fn (Get $get): bool => filled($get('received_preview_matter')))
                                ->content(function (Get $get): HtmlString {
                                    $matter = Matter::find($get('received_preview_matter'));
                                    $template = trim((string) $get('received_emails'));

                                    if (! $matter || $template === '') {
                                        return new HtmlString('—');
                                    }

                                    $path = '<code dir="auto">'.e(MatterOneDriveFolders::folderName($matter).'/'.MatterOneDriveFolders::resolvePath($template, $matter, now())).'</code>';

                                    // A folder the matter has no value for is left out of the path: said, so it is not a surprise.
                                    $empty = MatterOneDriveFolders::emptyPlaceholders($template, $matter, now());

                                    return new HtmlString($path.($empty === []
                                        ? ''
                                        : '<div style="color: #b45309; margin-top: .35rem;">'.e(__('This matter has no value for :placeholders — its folder is left out.', ['placeholders' => implode('، ', $empty)])).'</div>'));
                                }),
                        ]),
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
                        // The last run: one row per assistant.
                        EntryTable::make('test_results', [
                            TextEntry::make('name')->label(__('Assistant')),
                            TextEntry::make('email')->label(__('OneDrive account'))->extraAttributes(['dir' => 'ltr']),
                            TextEntry::make('message')
                                ->label(__('Result'))
                                ->icon(fn (TextEntry $component): Heroicon => EntryTable::row($component)['ok'] ? Heroicon::OutlinedCheckCircle : Heroicon::OutlinedXCircle)
                                ->color(fn (TextEntry $component): string => EntryTable::row($component)['ok'] ? 'success' : 'danger'),
                            TextEntry::make('url')
                                ->label(__('Open'))
                                ->hiddenLabel()
                                ->formatStateUsing(fn (): string => __('Open'))
                                ->url(fn (string $state): string => $state, shouldOpenInNewTab: true)
                                ->color('primary'),
                        ])
                            ->visible(fn (): bool => $this->testResults !== [])
                            ->state(fn (): array => $this->testResults),
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
            SettingsForm::make(),

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
        Setting::set(MatterOneDriveFolders::SHORTCUTS, (bool) ($state['shortcuts'] ?? false), 'onedrive');
        Setting::set(MatterOneDriveFolders::FOLDER_CHECK, (bool) ($state['folder_check'] ?? false), 'onedrive');
        Setting::set(MatterOneDriveFolders::SUBFOLDERS, trim((string) ($state['subfolders'] ?? '')), 'onedrive');
        Setting::set(MatterOneDriveFolders::MATTERS_FOLDER, trim((string) ($state['matters_folder'] ?? '')), 'onedrive');
        Setting::set(MatterOneDriveFolders::FINISHED_FOLDER, trim((string) ($state['finished_folder'] ?? '')), 'onedrive');
        Setting::set(MatterOneDriveFolders::SIGNED_MINUTES, trim((string) ($state['signed_minutes'] ?? '')), 'onedrive');
        Setting::set(MatterOneDriveFolders::SENT_EMAILS, trim((string) ($state['sent_emails'] ?? '')), 'onedrive');
        Setting::set(MatterOneDriveFolders::SENT_TO_CREDITORS, trim((string) ($state[MatterOneDriveFolders::SENT_TO_CREDITORS] ?? '')), 'onedrive');
        Setting::set(MatterOneDriveFolders::RECEIVED_EMAILS, trim((string) ($state['received_emails'] ?? '')), 'onedrive');

        foreach (self::keyedSettings() as $key) {
            Setting::set($key, trim((string) ($state[$key] ?? '')), 'onedrive');
        }

        Notification::make()->title(__('Settings saved successfully'))->success()->send();
    }

    /**
     * Every assistant with a OneDrive account: their two folders given the standard names (StandardiseAssistantFolders),
     * one a job.
     */
    public function applyStandardFolders(): void
    {
        $assistants = Party::query()->whereNotNull('onedrive_email')->where('onedrive_email', '!=', '')->get(['id', 'onedrive_email']);

        foreach ($assistants as $assistant) {
            StandardiseAssistantFolders::dispatch((int) $assistant->getKey(), auth()->id())->afterCommit();
        }

        Notification::make()
            ->info()
            ->title(trans_choice('No assistant has a OneDrive account.|The folders of :count assistant are being renamed.|The folders of :count assistants are being renamed.', $assistants->count(), ['count' => $assistants->count()]))
            ->body($assistants->isEmpty() ? null : __('In the background, one assistant at a time; you are told in the bell what was done for each.'))
            ->send();
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
                $row['message'] = $exception->getMessage() ?: __('Failed');
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
