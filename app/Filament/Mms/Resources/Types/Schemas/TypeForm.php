<?php

namespace App\Filament\Mms\Resources\Types\Schemas;

use App\Models\LetterTemplate;
use App\Models\Setting;
use App\Models\Type;
use App\Services\MMS\MatterOneDriveFolders;
use App\Services\MMS\PeriodicReports;
use App\Services\MMS\ReceivedEmailFiling;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class TypeForm
{
    /** Not saved on the type: whether its folders are given to the types of its group too (EditType). */
    public const FOLDERS_TO_GROUP = 'folders_to_group';

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                // Two columns, each its sections one under another.
                Grid::make(1)->columnSpan(1)->schema([
                    // What the type is.
                    Section::make(__('General Information'))
                        ->icon(Heroicon::OutlinedInformationCircle)
                        ->schema([
                            TextInput::make('name')
                                ->label(__('Name'))
                                ->required(),
                            Toggle::make('active')
                                ->label(__('Active'))
                                ->default(true)
                                ->required(),
                            Toggle::make('has_phases')
                                ->label(__('Has phases'))
                                ->helperText(__('Each of its matters is in one of its phases, chosen when it is made (a supplementary matter: the next one) — {{matter.phase}} in letters and folders.'))
                                ->live(),
                            TagsInput::make('phases')
                                ->label(__('Phases, in order'))
                                ->placeholder(__('e.g. First phase'))
                                ->helperText(__('Press Enter after each; drag to put them in order.'))
                                ->splitKeys(['Enter'])
                                ->reorderable()
                                ->required(fn (Get $get): bool => (bool) $get('has_phases'))
                                ->visible(fn (Get $get): bool => (bool) $get('has_phases')),
                            Toggle::make('has_creditors')
                                ->label(__('Has creditors'))
                                ->helperText(__('Its matters keep a list of creditors and their agents, who are no parties (a bankruptcy\'s): on their Contacts tab, their emails saved in a folder of their own, exported for bulk mail.'))
                                ->live(),
                        ]),
                    // What the sides are called.
                    Section::make(__('Party capacities'))
                        ->description(__('What each side is called in this type\'s matters — in letters, minutes and the matter page. Leave empty for the usual names.'))
                        ->icon(Heroicon::OutlinedUsers)
                        ->schema(self::capacityFields()),
                    // The folders made for its matters.
                    Section::make(__('OneDrive folder structure'))
                        ->description(__('The subfolders of this type\'s matter folders in the assistants\' OneDrive. Leave empty for the default structure (Settings → OneDrive Folders).'))
                        ->icon(Heroicon::OutlinedFolderOpen)
                        ->collapsed()
                        ->schema([
                            self::oneDriveStructureField(),
                            self::receivedEmailsField(),
                            // The emails from each side apart, each named as set.
                            ...array_map(fn (string $side): TextInput => self::receivedSideFolderField($side), ReceivedEmailFiling::APART),
                            ...array_map(fn (string $side): TextInput => self::receivedNameField($side), [ReceivedEmailFiling::GENERAL, ...ReceivedEmailFiling::APART]),
                            self::sentEmailsField(),
                            self::signedMinutesField(),
                            self::periodicReportsField(),
                            // Its group — the types with the same folders: changed with it, or left as they are (EditType).
                            Toggle::make(self::FOLDERS_TO_GROUP)
                                ->label(fn (?Type $record): string => __('Also for the types with the same folders: :types', [
                                    'types' => $record?->folderGroupmates()->pluck('name')->implode('، '),
                                ]))
                                ->default(true)
                                ->visible(fn (?Type $record): bool => (bool) $record?->folderGroupmates()->isNotEmpty()),
                        ]),
                ]),
                Grid::make(1)->columnSpan(1)->schema([
                    Section::make(__('Incentive Configuration'))
                        ->icon(Heroicon::OutlinedCalculator)
                        ->schema([
                            Select::make('incentive_config_id')
                                ->label(__('Incentive Configuration'))
                                ->relationship('incentiveConfig', 'name')
                                ->searchable(),
                            Select::make('incentive_trigger_type')
                                ->label(__('Incentive Trigger Type'))
                                ->options([
                                    'final_report_date' => __('Matter Final Reported'),
                                    'fees_registered_date' => __('Fee Registered'),
                                ])
                                ->required(),
                            Toggle::make('allow_current_status_import')
                                ->label(__('Allow Current Status Import'))
                                ->helperText(__('If enabled, matters can be imported for incentives even if not final reported, as long as they have collected fees.'))
                                ->default(false),
                            Toggle::make('exclude_from_incentive_count')
                                ->label(__('Exclude from Incentive Count'))
                                ->helperText(__('If enabled, matters of this type will not be counted towards the monthly total for extra incentive percentages.'))
                                ->default(false),
                        ]),
                    Section::make(__('Letter templates'))
                        ->description(__('The letter templates offered on this type\'s matters. A template linked to no type is offered for every type; linking it to types here limits it to those.'))
                        ->icon(Heroicon::OutlinedDocumentText)
                        ->schema([
                            Select::make('letter_templates_offered')
                                ->label(__('Offered for this type'))
                                ->options(fn (Get $get): array => LetterTemplate::query()->whereNotIn('id', (array) $get('letter_templates_excluded'))->orderBy('name')->pluck('name', 'id')->all())
                                ->multiple()
                                ->searchable()
                                ->live()
                                ->dehydrated(false),
                            Select::make('letter_templates_excluded')
                                ->label(__('Offered for every type except this one'))
                                ->helperText(__('A template for all types except the ones it is left out for.'))
                                ->options(fn (Get $get): array => LetterTemplate::query()->whereNotIn('id', (array) $get('letter_templates_offered'))->orderBy('name')->pluck('name', 'id')->all())
                                ->multiple()
                                ->searchable()
                                ->live()
                                ->dehydrated(false),
                        ]),
                ]),
            ]);
    }

    /**
     * Where the emails that come in are saved inside the matter's folder, for this type:
     * a path whose folders may use the matter's placeholders. Empty: the default one.
     */
    public static function receivedEmailsField(string $name = 'onedrive_received_emails'): TextInput
    {
        return TextInput::make($name)
            ->label(__('Received emails folder structure'))
            ->helperText(__('Where the emails that come in are saved inside the matter\'s folder, for this type\'s matters. Folders are separated by "/", and each may use the matter\'s placeholders, like the letters do, e.g. {{matter.custom.Phase}}/01 system. {{day}} is the day the email reached us, so the emails of one day go in the same folder. Leave empty for the default one (Settings → OneDrive Folders).'))
            ->placeholder(fn (): string => MatterOneDriveFolders::receivedEmailsFolder())
            ->maxLength(500)
            ->extraInputAttributes(['dir' => 'ltr']);
    }

    /**
     * Where the emails from a side (ReceivedEmailFiling) are saved inside the matter's folder — for this type, or
     * (not $forType) the setting. Empty: the setting's, else with the other received emails.
     */
    public static function receivedSideFolderField(string $side, bool $forType = true): TextInput
    {
        $creditor = $side === ReceivedEmailFiling::CREDITOR;

        return TextInput::make($forType ? ReceivedEmailFiling::FOLDER_COLUMNS[$side] : ReceivedEmailFiling::FOLDER_SETTINGS[$side])
            ->label(match ($side) {
                ReceivedEmailFiling::PLAINTIFF => __('Emails from the plaintiff side — folder'),
                ReceivedEmailFiling::DEFENDANT => __('Emails from the defendant side — folder'),
                default => __('Emails from creditors — folder'),
            })
            ->helperText($creditor
                ? __('Where the emails from the matter\'s creditors and their agents (its Contacts tab) are saved inside the matter\'s folder. Written like the received emails folder structure; {{creditor}} is the creditor\'s name — a folder for each, e.g. 07 مطالبات الدائنين/{{creditor}}. Leave empty to save them with the other received emails.')
                : __('Where the emails from this side are saved inside the matter\'s folder: its parties, their representatives (on the matter or at a meeting) and whoever they copied in. Written like the received emails folder structure. Leave empty to save them with the other received emails.'))
            ->placeholder(fn (): string => $forType ? ReceivedEmailFiling::folderTemplate(null, $side) : MatterOneDriveFolders::receivedEmailsFolder())
            // A type's creditors' only for a type that has them.
            ->visible(fn (Get $get): bool => ! $forType || ! $creditor || (bool) $get('has_creditors'))
            ->maxLength(500)
            ->extraInputAttributes(['dir' => 'ltr']);
    }

    /**
     * Where a periodic report's files are moved inside the matter's folder (PeriodicReports) — for this type, or (not
     * $forType) the setting. Empty: the setting's, else the default one.
     */
    public static function periodicReportsField(string $name = PeriodicReports::COLUMN, bool $forType = true): TextInput
    {
        return TextInput::make($name)
            ->label(__('Periodic report folder'))
            ->helperText(__('Where the files of the progress steps put in a periodic report are moved, inside the matter\'s folder. {{number}} is the report\'s number; folders are separated by "/", and each may use the matter\'s placeholders, e.g. {{matter.custom.Phase}}/05 التقرير/تقرير دوري رقم {{number}}.').($forType ? ' '.__('Leave empty for the default one (Settings → OneDrive Folders).') : ''))
            ->placeholder(fn (): string => $forType ? PeriodicReports::template() : PeriodicReports::DEFAULT_FOLDER)
            ->maxLength(500)
            ->extraInputAttributes(['dir' => 'auto']);
    }

    /**
     * The name of a received email's PDF, its original and the folder of its files — of a side, or the general one
     * (ReceivedEmailFiling) — for this type, or (not $forType) the setting. Empty: the setting's, else the general one.
     */
    public static function receivedNameField(string $side, bool $forType = true): TextInput
    {
        $general = $side === ReceivedEmailFiling::GENERAL;
        $creditor = $side === ReceivedEmailFiling::CREDITOR;

        return TextInput::make($forType ? ReceivedEmailFiling::NAME_COLUMNS[$side] : ReceivedEmailFiling::NAME_SETTINGS[$side])
            ->label(match ($side) {
                ReceivedEmailFiling::PLAINTIFF => __('Emails from the plaintiff side — file name'),
                ReceivedEmailFiling::DEFENDANT => __('Emails from the defendant side — file name'),
                ReceivedEmailFiling::CREDITOR => __('Emails from creditors — file name'),
                default => __('Received emails — file name'),
            })
            ->helperText(__('The name of each email\'s PDF, its original and the folder of its files. Placeholders: {{date}}, {{time}}, {{kind}} (incoming or reply), {{party}} (who sent it), {{subject}} (what it is about), {{side}} (the side\'s name) and the matter\'s, e.g. {{matter.number}}. A part with no value is left out with its " — ".')
                .($creditor ? ' '.__('{{creditor}} is the creditor\'s name, and {{side}} "Creditor" or "Creditor\'s agent".') : '')
                .($general ? '' : ' '.__('Leave empty to name them as the other received emails.')))
            ->visible(fn (Get $get): bool => ! $forType || ! $creditor || (bool) $get('has_creditors'))
            ->placeholder(fn (): string => match (true) {
                $forType => ReceivedEmailFiling::nameTemplate(null, $side) ?: ReceivedEmailFiling::DEFAULT_NAME,
                $general => ReceivedEmailFiling::DEFAULT_NAME,
                default => ReceivedEmailFiling::nameTemplate(null, ReceivedEmailFiling::GENERAL) ?: ReceivedEmailFiling::DEFAULT_NAME,
            })
            ->maxLength(500)
            ->extraInputAttributes(['dir' => 'auto']);
    }

    /**
     * Where the emails that are sent are saved inside the matter's folder, for this type:
     * a path whose folders may use the matter's placeholders. Empty: the default one.
     */
    public static function sentEmailsField(string $name = 'onedrive_sent_emails'): TextInput
    {
        return TextInput::make($name)
            ->label(__('Sent emails folder'))
            ->helperText(__('Where the emails sent from the matter are saved, for this type\'s matters. Folders are separated by "/", and each may use the matter\'s placeholders, like the letters do, e.g. {{matter.custom.Phase}}/Sent/{{day}}. {{day}} is the day it is sent. Leave empty for the default one (Settings → OneDrive Folders).'))
            ->placeholder(fn (): string => MatterOneDriveFolders::sentEmailsFolder())
            ->maxLength(500)
            ->extraInputAttributes(['dir' => 'ltr']);
    }

    /**
     * Where the signed minutes are saved inside the matter's folder, for this type.
     * Empty: the default one.
     */
    public static function signedMinutesField(string $name = 'onedrive_signed_minutes'): TextInput
    {
        return TextInput::make($name)
            ->label(__('Signed minutes folder'))
            ->helperText(__('Where the signed minutes are saved, for this type\'s matters. Folders are separated by "/", and each may use the matter\'s placeholders, like the letters do, e.g. {{matter.custom.Phase}}/06 signed minutes. Leave empty for the default one (Settings → OneDrive Folders).'))
            ->placeholder(fn (): string => MatterOneDriveFolders::signedMinutesFolder())
            ->maxLength(500)
            ->extraInputAttributes(['dir' => 'ltr']);
    }

    /**
     * The subfolders, one per line — also for the types table's bulk
     * action and the OneDrive Folders page.
     */
    public static function oneDriveStructureField(string $name = 'onedrive_subfolders'): Textarea
    {
        return Textarea::make($name)
            ->label(__('Subfolders'))
            ->helperText(__('One per line, in order. Use "/" for a folder inside another, e.g. "02 المستندات/من المدعي". Changes apply to folders made from now on.').' '.__('A folder may use the matter\'s placeholders, like the letters do, e.g. {{matter.custom.phase}}/01 المراسلات; one the matter has no value for is left out.'))
            ->placeholder(fn (): string => Setting::get(MatterOneDriveFolders::SUBFOLDERS, ''))
            ->rows(8)
            ->extraInputAttributes(['dir' => 'auto']);
    }

    /**
     * The three sides' names, with common pairs to pick from — also for the
     * types table's bulk action.
     *
     * @return list<mixed>
     */
    public static function capacityFields(): array
    {
        return [
            Select::make('capacity_preset')
                ->label(__('Common names'))
                ->options(collect(Type::CAPACITY_PRESETS)->mapWithKeys(fn (array $pair, int $i) => [$i => $pair[0].' / '.$pair[1]])->all())
                ->placeholder(__('Pick to fill in'))
                ->dehydrated(false)
                ->live()
                ->afterStateUpdated(function ($state, Set $set): void {
                    if (filled($state) && ($pair = Type::CAPACITY_PRESETS[(int) $state] ?? null)) {
                        $set('party_capacities.plaintiff', $pair[0]);
                        $set('party_capacities.defendant', $pair[1]);
                    }
                })
                ->columnSpanFull(),
            TextInput::make('party_capacities.plaintiff')
                ->label(__('Plaintiff side'))
                ->placeholder('المدعي'),
            TextInput::make('party_capacities.defendant')
                ->label(__('Defendant side'))
                ->placeholder('المدعى عليه'),
            TextInput::make('party_capacities.implicate-litigant')
                ->label(__('Implicated litigant'))
                ->placeholder('الخصم المدخل'),
        ];
    }
}
