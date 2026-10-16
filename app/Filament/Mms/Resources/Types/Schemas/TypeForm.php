<?php

namespace App\Filament\Mms\Resources\Types\Schemas;

use App\Models\LetterTemplate;
use App\Models\Setting;
use App\Models\Type;
use App\Services\MMS\MatterOneDriveFolders;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class TypeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                // Row 1: what the type is, and how its matters earn incentives.
                Section::make(__('General Information'))
                    ->icon(Heroicon::OutlinedInformationCircle)
                    ->columnSpan(1)
                    ->schema([
                        TextInput::make('name')
                            ->label(__('Name'))
                            ->required(),
                        Toggle::make('active')
                            ->label(__('Active'))
                            ->default(true)
                            ->required(),
                    ]),
                Section::make(__('Incentive Configuration'))
                    ->icon(Heroicon::OutlinedCalculator)
                    ->columnSpan(1)
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

                // Row 2: what the sides are called, and which letters are offered.
                Section::make(__('Party capacities'))
                    ->description(__('What each side is called in this type\'s matters — in letters, minutes and the matter page. Leave empty for the usual names.'))
                    ->icon(Heroicon::OutlinedUsers)
                    ->columnSpan(1)
                    ->schema(self::capacityFields()),
                Section::make(__('Letter templates'))
                    ->description(__('The letter templates offered on this type\'s matters. A template linked to no type is offered for every type; linking it to types here limits it to those.'))
                    ->icon(Heroicon::OutlinedDocumentText)
                    ->columnSpan(1)
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

                // Row 3, across: the folders made for its matters.
                Section::make(__('OneDrive folder structure'))
                    ->description(__('The subfolders of this type\'s matter folders in the assistants\' OneDrive. Leave empty for the default structure (Settings → OneDrive Folders).'))
                    ->icon(Heroicon::OutlinedFolderOpen)
                    ->columnSpanFull()
                    ->collapsed()
                    ->schema([
                        self::oneDriveStructureField(),
                        self::receivedEmailsField(),
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
