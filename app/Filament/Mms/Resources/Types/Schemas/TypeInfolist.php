<?php

namespace App\Filament\Mms\Resources\Types\Schemas;

use App\Enums\MatterDifficulty;
use App\Filament\Mms\Resources\Incentive\MatterTypeIncentiveConfigs\MatterTypeIncentiveConfigResource;
use App\Models\LetterTemplate;
use App\Models\Type;
use App\Services\MMS\MatterOneDriveFolders;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;

/**
 * A matter type, all it governs in one place: its matters, how incentives
 * are calculated for them, what their sides are called, their OneDrive
 * folder structure, their own fields and the letter templates offered.
 */
class TypeInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('General Information'))
                    ->icon(Heroicon::OutlinedInformationCircle)
                    ->columns(3)
                    ->columnSpanFull()
                    ->components([
                        TextEntry::make('name')
                            ->label(__('Name'))
                            ->icon(Heroicon::OutlinedTag)
                            ->weight('bold')
                            ->columnSpanFull(),
                        IconEntry::make('active')->label(__('Active'))->boolean(),
                        IconEntry::make('allow_current_status_import')->label(__('Allow Current Status Import'))->boolean(),
                        IconEntry::make('exclude_from_incentive_count')->label(__('Exclude from Incentive Count'))->boolean(),
                        TextEntry::make('matters_count')
                            ->counts('matters')
                            ->label(__('Total Matters'))
                            ->icon(Heroicon::OutlinedFolder)
                            ->numeric(),
                        // Still being worked on: no final report yet.
                        TextEntry::make('active_matters')
                            ->label(__('Active matters'))
                            ->state(fn (Type $record): int => $record->matters()->whereNull('final_report_at')->count())
                            ->icon(Heroicon::OutlinedBriefcase)
                            ->numeric(),
                    ]),

                Section::make(__('Incentive Configuration'))
                    ->icon(Heroicon::OutlinedCalculator)
                    ->columns(3)
                    ->columnSpanFull()
                    ->components([
                        TextEntry::make('incentive_trigger_type')
                            ->label(__('Incentive Trigger Type'))
                            ->badge()
                            ->formatStateUsing(fn ($state) => match ($state) {
                                'final_report_date' => __('Matter Final Reported'),
                                'fees_registered_date' => __('Fee Registered'),
                                default => $state ? __($state) : '-',
                            }),
                        TextEntry::make('incentiveConfig.name')
                            ->label(__('Incentive Configuration'))
                            ->placeholder(__('None'))
                            ->url(fn (Type $record): ?string => $record->incentiveConfig ? MatterTypeIncentiveConfigResource::getUrl('view', ['record' => $record->incentiveConfig]) : null)
                            ->color('primary'),
                        TextEntry::make('incentiveConfig.calculation_type')
                            ->label(__('Calculation Type'))
                            ->badge()
                            ->placeholder('—')
                            ->formatStateUsing(fn (?string $state): string => match ($state) {
                                'tiered' => __('Tiered — % by working days & difficulty'),
                                'fixed' => __('Fixed — fixed % for all fees'),
                                'committee' => __('Committee — tiered ± 2%'),
                                default => (string) $state,
                            }),
                        TextEntry::make('incentiveConfig.fixed_percentage')
                            ->label(__('Fixed Percentage (%)'))
                            ->suffix('%')
                            ->visible(fn (Type $record): bool => $record->incentiveConfig?->calculation_type === 'fixed'),
                        TextEntry::make('incentiveConfig.assistant_rate')
                            ->label(__('Assistant Rate (%)'))
                            ->suffix('%')
                            ->placeholder('—'),
                        RepeatableEntry::make('incentiveConfig.tiers')
                            ->label(__('Tiers'))
                            ->visible(fn (Type $record): bool => (bool) $record->incentiveConfig?->tiers()->exists())
                            ->table([
                                RepeatableEntry\TableColumn::make(__('Difficulty')),
                                RepeatableEntry\TableColumn::make(__('Days From')),
                                RepeatableEntry\TableColumn::make(__('Days To')),
                                RepeatableEntry\TableColumn::make(__('Percentage')),
                            ])
                            ->schema([
                                TextEntry::make('difficulty')
                                    ->formatStateUsing(fn ($state): string => $state instanceof MatterDifficulty ? (string) $state->getLabel() : (MatterDifficulty::tryFrom((string) $state)?->getLabel() ?? (string) $state)),
                                TextEntry::make('days_from'),
                                TextEntry::make('days_to')->placeholder('∞'),
                                TextEntry::make('percentage')->suffix('%'),
                            ])
                            ->columnSpanFull(),
                    ]),

                Section::make(__('Party capacities'))
                    ->description(__('What each side is called in this type\'s matters — in letters, minutes and the matter page.'))
                    ->icon(Heroicon::OutlinedUsers)
                    ->columns(3)
                    ->columnSpanFull()
                    ->components(collect(Type::SIDES)->map(fn (string $side) => TextEntry::make('capacity_'.$side)
                        ->label(Type::sideLabel(null, $side))
                        ->state(fn (Type $record): string => Type::sideLabel($record, $side))
                        // The type's own name, or the usual one.
                        ->helperText(fn (Type $record): ?string => $record->capacity($side) ? null : __('The usual name'))
                        ->weight(fn (Type $record): ?string => $record->capacity($side) ? 'bold' : null))
                        ->all()),

                Section::make(__('OneDrive folder structure'))
                    ->description(__('The subfolders made in each of this type\'s matter folders in the assistants\' OneDrive.'))
                    ->icon(Heroicon::OutlinedFolderOpen)
                    ->columnSpanFull()
                    ->components([
                        TextEntry::make('onedrive_source')
                            ->hiddenLabel()
                            ->state(fn (Type $record): string => filled($record->onedrive_subfolders) ? __('This type\'s own structure') : __('The default structure (Settings → OneDrive Folders)'))
                            ->badge()
                            ->color(fn (Type $record): string => filled($record->onedrive_subfolders) ? 'success' : 'gray'),
                        TextEntry::make('onedrive_structure')
                            ->hiddenLabel()
                            ->state(fn (Type $record): HtmlString => self::tree(MatterOneDriveFolders::subfolders($record)))
                            ->html(),
                    ]),

                Section::make(__('Custom fields'))
                    ->description(__('The fields this type\'s matters have besides the usual ones.'))
                    ->icon(Heroicon::OutlinedRectangleStack)
                    ->columnSpanFull()
                    ->collapsible()
                    ->components([
                        TextEntry::make('custom_fields')
                            ->hiddenLabel()
                            ->state(fn (Type $record): HtmlString|string => $record->fieldDefinitions->isEmpty()
                                ? __('None')
                                : new HtmlString('<ul style="list-style: disc; padding-inline-start: 1.25rem;">'.$record->fieldDefinitions
                                    ->map(fn ($field): string => '<li>'.e($field->label).' <span style="opacity: .65;">('.e(__(ucfirst((string) $field->type))).($field->required ? ' · '.e(__('Required')) : '').')</span></li>')
                                    ->implode('').'</ul>'))
                            ->html(),
                    ]),

                Section::make(__('Letter templates'))
                    ->description(__('The letter templates offered for this type\'s matters.'))
                    ->icon(Heroicon::OutlinedDocumentText)
                    ->columnSpanFull()
                    ->collapsible()
                    ->components([
                        // Those linked to this type, then the general ones (linked to no type).
                        TextEntry::make('letter_templates_own')
                            ->label(__('For this type'))
                            ->state(fn (Type $record): string => $record->letterTemplates->where('is_active', true)->pluck('name')->implode('، ') ?: __('None')),
                        TextEntry::make('letter_templates_general')
                            ->label(__('General (for every type)'))
                            ->state(fn (): string => LetterTemplate::query()->where('is_active', true)->whereDoesntHave('types')->orderBy('name')->pluck('name')->implode('، ') ?: __('None')),
                    ]),

                Section::make(__('Timestamps'))
                    ->icon(Heroicon::OutlinedClock)
                    ->columns(2)
                    ->columnSpanFull()
                    ->collapsed()
                    ->components([
                        TextEntry::make('created_at')->label(__('Created'))->dateTime()->placeholder('-')->icon(Heroicon::OutlinedCalendar),
                        TextEntry::make('updated_at')->label(__('Updated'))->dateTime()->placeholder('-')->icon(Heroicon::OutlinedCalendar),
                    ]),
            ]);
    }

    /**
     * The subfolders as the tree they make: "02 المستندات/من المدعي" under
     * "02 المستندات".
     *
     * @param  list<string>  $lines
     */
    private static function tree(array $lines): HtmlString
    {
        if ($lines === []) {
            return new HtmlString(e(__('No subfolders — the matter folder alone.')));
        }

        // Each folder once, at its depth — "a/b" shows a, then b under it.
        $shown = [];
        $html = '';

        foreach ($lines as $line) {
            $segments = array_values(array_filter(array_map('trim', explode('/', $line)), 'filled'));

            foreach ($segments as $depth => $segment) {
                $path = implode('/', array_slice($segments, 0, $depth + 1));

                if (! isset($shown[$path])) {
                    $shown[$path] = true;
                    $html .= '<div dir="auto" style="padding-inline-start: '.($depth * 1.5).'rem;">📁 '.e($segment).'</div>';
                }
            }
        }

        return new HtmlString($html);
    }
}
