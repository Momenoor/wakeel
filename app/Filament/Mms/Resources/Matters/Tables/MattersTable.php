<?php

namespace App\Filament\Mms\Resources\Matters\Tables;

use App\Enums\MatterCollectionStatus;
use App\Filament\Mms\Concerns\HasMultiWordSearch;
use App\Models\Court;
use App\Models\Party;
use App\Models\Type;
use Carbon\Carbon;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Fieldset;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class MattersTable
{
    use HasMultiWordSearch;

    private const DIGITS = ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9'];

    public static function configure(Table $table): Table
    {
        return $table
            // One search for the whole table (search()), not one per column.
            ->searchUsing(fn (Builder $query, string $search) => static::search($query, $search))
            ->striped()
            ->extraAttributes([
                'class' => 'custom-compact-table [&_td]:py-1 [&_th]:py-1 [&_table]:text-sm [&_table]:w-full',
            ])
            ->columns([

                // ── Reference ─────────────────────────────────────────────────
                TextColumn::make('reference')
                    ->label(__('Matter'))
                    ->getStateUsing(fn ($record) => $record->year.'/'.$record->number)
                    ->weight(FontWeight::Bold)
                    ->description(fn ($record) => $record->status->getLabel())
                    ->color(fn ($record) => $record->parent_id ? 'primary' : null)
                    // ps-6 (padding-inline-start), not pl-6: this indents child
                    // matters under their parent, and the panel's default locale
                    // is Arabic, where a physical `pl` indents the wrong side.
                    // The logical property also removes the need for the old
                    // locale-branching arrow prefix ('↳' vs '↲').
                    ->extraAttributes(fn ($record) => $record->parent_id
                        ? ['class' => 'ps-6 opacity-90']
                        : []
                    )
                    ->searchable(query: function (Builder $query, string $search) {
                        $tokens = static::splitSearch($search);
                        if (count($tokens) === 2 && is_numeric($tokens[0]) && is_numeric($tokens[1])) {
                            return $query->where(function ($q) use ($tokens) {
                                foreach ($tokens as $token) {
                                    $q->where(function ($inner) use ($token) {
                                        $inner->orWhere('year', $token)
                                            ->orWhere('number', $token)
                                            ->orWhere('number', '0'.$token);
                                    });
                                }
                            });
                        }

                        return static::applyMultiWordSearch($query, $search, ['year', 'number']);
                    })
                    ->toggleable()
                    ->grow(false)
                    ->width('7%'),

                // ── Court / Type ───────────────────────────────────────────────
                TextColumn::make('court.name')
                    ->label(__('Court / Type'))
                    ->description(fn ($record) => $record->type?->name)
                    ->searchable(query: fn (Builder $query, string $search) => static::applyMultiWordSearch($query, $search, ['court.name', 'type.name'])
                    )
                    ->wrap()
                    ->toggleable()
                    ->grow(false)
                    ->width('12%'),

                // ── Level ──────────────────────────────────────────────────────
                TextColumn::make('level')
                    ->label(__('Level'))
                    ->badge()
                    ->description(fn ($record) => collect([
                        $record->difficulty?->getLabel(),
                        $record->commissioning?->getLabel(),
                    ])->filter()->join(' · '))
                    ->searchable(query: fn (Builder $query, string $search) => static::applyMultiWordSearch($query, $search, ['level', 'difficulty', 'commissioning'])
                    )
                    ->sortable()
                    ->toggleable()
                    ->grow(false)
                    ->width('10%'),

                // ── Parties — hidden for child rows ────────────────────────────
                TextColumn::make('indexedParties')
                    ->label(__('Parties'))
                    ->getStateUsing(fn ($record) => $record->indexedParties
                        ->map(fn ($mp) => [
                            'label' => __($mp->type ? ucfirst(str_replace('-', ' ', $mp->type)) : ''),
                            'index' => $mp->role_index,
                            'name' => $mp->party?->name ?? '—',
                            'color' => match ($mp->type) {
                                'plaintiff' => 'success',
                                'defendant' => 'danger',
                                'implicate-litigant' => 'warning',
                                default => 'gray',
                            },
                        ])
                        ->all()
                    )
                    ->view('filament.tables.columns.party-badges')
                    ->grow()
                    ->searchable(query: function (Builder $query, string $search) {
                        $tokens = static::splitSearch($search);
                        foreach ($tokens as $token) {
                            $query->whereHas('mainPartiesOnly.party', fn ($q) => $q->where('name', 'like', "%{$token}%")
                            );
                        }

                        return $query;
                    })
                    ->wrap()
                    ->width('26%')
                    ->toggleable(),

                // ── Experts ────────────────────────────────────────────────────
                TextColumn::make('indexedExperts')
                    ->label(__('Experts'))
                    ->getStateUsing(fn ($record) => $record->indexedExperts
                        ->map(fn ($mp) => [
                            'label' => __($mp->type ? ucfirst(str_replace('-', ' ', $mp->type)) : ''),
                            'index' => $mp->role_index,
                            'name' => $mp->party?->name ?? '—',
                            'color' => match ($mp->type) {
                                'certified' => 'primary',
                                'assistant' => 'success',
                                'external' => 'warning',
                                'external-assistant' => 'danger',
                                default => 'gray',
                            },
                        ])
                        ->all()
                    )
                    ->view('filament.tables.columns.party-badges')
                    ->searchable(query: function (Builder $query, string $search) {
                        $tokens = static::splitSearch($search);
                        foreach ($tokens as $token) {
                            $query->whereHas('expertsOnly.party', fn ($q) => $q->where('name', 'like', "%{$token}%")
                            );
                        }

                        return $query;
                    })
                    ->wrap()
                    ->width('20%')
                    ->toggleable(),

                // ── Fees ───────────────────────────────────────────────────────
                TextColumn::make('fees_summary')
                    ->label(__('Fees'))
                    ->getStateUsing(fn ($record) => number_format($record->fees->sum('amount'), 2))
                    ->description(fn ($record) => number_format(
                        $record->fees->sum(fn ($fee) => $fee->allocations->sum('amount')), 2
                    ))
                    ->color(function ($record) {
                        $total = (float) $record->fees->sum('amount');
                        $collected = (float) $record->fees->sum(fn ($fee) => $fee->allocations->sum('amount'));
                        if ($total <= 0) {
                            return 'gray';
                        }
                        if ($collected >= $total) {
                            return 'success';
                        }
                        if ($collected > 0) {
                            return 'warning';
                        }

                        return 'danger';
                    })
                    ->searchable(false)
                    ->grow(false)
                    ->width('10%')
                    ->toggleable(),

                // ── Next Session ───────────────────────────────────────────────
                TextColumn::make('next_session_date')
                    ->label(__('Next Session'))
                    ->date()
                    ->description(fn ($record) => $record->distributed_at
                        ? __('Report').': '.Carbon::parse($record->distributed_at)->format('M d, Y')
                        : null
                    )
                    ->sortable()
                    ->grow(false)
                    ->width('10%'),

                TextColumn::make('initial_report_at')
                    ->date()->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('final_report_at')
                    ->date()->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->dateTime()->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('updated_at')
                    ->dateTime()->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('notes.text')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([

                SelectFilter::make('collection_status')
                    ->label(__('Collection Status'))
                    ->options(MatterCollectionStatus::class)
                    ->multiple()
                    ->searchable()
                    ->preload()
                    ->columnSpan(2),

                SelectFilter::make('commissioning')
                    ->label(__('Commissioning'))
                    ->options([
                        'individual' => __('Individual'),
                        'committee' => __('Committee'),
                    ])
                    ->multiple()
                    ->columnSpan(2),

                SelectFilter::make('assistant_expert')
                    ->label(__('Assistant Expert'))
                    ->options(function () {
                        return Party::query()
                            ->whereExists(function ($query) {
                                $query->select('party_id')
                                    ->from('matter_party')
                                    ->whereColumn('matter_party.party_id', 'parties.id')
                                    ->whereIn('matter_party.type', ['assistant', 'external-assistant']);
                            })
                            ->orderBy('name')
                            ->pluck('name', 'id');
                    })
                    ->query(function (Builder $query, array $data) {
                        if (empty($data['values'])) {
                            return $query;
                        }

                        return $query->whereHas('matterParties', function ($q) use ($data) {
                            $q->whereIn('party_id', $data['values'])
                                ->whereIn('type', ['assistant', 'external-assistant']);
                        });
                    })
                    ->searchable()
                    ->multiple()
                    ->preload()
                    ->columnSpan(2),
                Filter::make('type')
                    ->label(__('Type'))
                    ->Schema([
                        Radio::make('type_filter_mode')
                            ->label(__('Filter Mode'))
                            ->options([
                                'only_selected' => __('Only selected type'),
                                'all_except_selected' => __('All without selected'),
                            ])
                            ->default('only_selected')
                            ->inline(),

                        Select::make('type_id')
                            ->label(__('Matter Type'))
                            ->options(fn () => Type::orderBy('name')->pluck('name', 'id'))
                            ->searchable()
                            ->preload()
                            ->multiple()
                            ->placeholder(__('Select a type')),
                    ])
                    ->query(function (Builder $query, array $data) {
                        if (empty($data['type_id'])) {
                            return $query;
                        }

                        $mode = $data['type_filter_mode'] ?? 'only_selected';

                        return $mode === 'all_except_selected'
                            ? $query->whereNotIn('type_id', $data['type_id'])
                            : $query->whereIn('type_id', $data['type_id']);
                    })
                    ->indicateUsing(function (array $data): array {
                        if (empty($data['type_id'])) {
                            return [];
                        }

                        $typeNames = Type::whereIn('id', (array) $data['type_id'])
                            ->pluck('name')
                            ->join(', ');

                        $mode = $data['type_filter_mode'] ?? 'only_selected';

                        return [
                            $mode === 'all_except_selected'
                                ? __('Type').': '.__('All without').' '.$typeNames
                                : __('Type').': '.$typeNames,
                        ];
                    })
                    ->columnSpan(3),

                Filter::make('distributed_at')
                    ->label(__('Received Date'))
                    ->schema([
                        Fieldset::make(__('Received Date'))->schema([
                            DatePicker::make('received_from')->label(__('From')),
                            DatePicker::make('received_until')->label(__('Until')),
                        ])->columns(2),
                    ])
                    ->query(function (Builder $query, array $data) {
                        return $query
                            ->when($data['received_from'], fn ($q, $v) => $q->whereDate('distributed_at', '>=', $v))
                            ->when($data['received_until'], fn ($q, $v) => $q->whereDate('distributed_at', '<=', $v));
                    })
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];
                        if ($data['received_from']) {
                            $indicators[] = __('Received from').': '.$data['received_from'];
                        }
                        if ($data['received_until']) {
                            $indicators[] = __('Received until').': '.$data['received_until'];
                        }

                        return $indicators;
                    })
                    ->columnSpan(3),

                Filter::make('next_session_date')
                    ->label(__('Next Session Date'))
                    ->schema([
                        Fieldset::make(__('Next Session Date'))->schema([
                            DatePicker::make('next_session_from')->label(__('From')),
                            DatePicker::make('next_session_until')->label(__('Until')),
                        ])->columns(2),
                    ])
                    ->query(function (Builder $query, array $data) {
                        return $query
                            ->when($data['next_session_from'], fn ($q, $v) => $q->whereDate('next_session_date', '>=', $v))
                            ->when($data['next_session_until'], fn ($q, $v) => $q->whereDate('next_session_date', '<=', $v));
                    })
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];
                        if ($data['next_session_from']) {
                            $indicators[] = __('Next session from').': '.$data['next_session_from'];
                        }
                        if ($data['next_session_until']) {
                            $indicators[] = __('Next session until').': '.$data['next_session_until'];
                        }

                        return $indicators;
                    })
                    ->columnSpan(3),

                Filter::make('initial_report_at')
                    ->label(__('Initial Report Date'))
                    ->schema([
                        Fieldset::make(__('Initial Report Date'))->schema([
                            DatePicker::make('reported_from')->label(__('From')),
                            DatePicker::make('reported_until')->label(__('Until')),
                        ])->columns(2),
                    ])
                    ->query(function (Builder $query, array $data) {
                        return $query
                            ->when($data['reported_from'], fn ($q, $v) => $q->whereDate('initial_report_at', '>=', $v))
                            ->when($data['reported_until'], fn ($q, $v) => $q->whereDate('initial_report_at', '<=', $v));
                    })
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];
                        if ($data['reported_from']) {
                            $indicators[] = __('Reported from').': '.$data['reported_from'];
                        }
                        if ($data['reported_until']) {
                            $indicators[] = __('Reported until').': '.$data['reported_until'];
                        }

                        return $indicators;
                    })
                    ->columnSpan(3),

                Filter::make('final_report_at')
                    ->label(__('Final Report Date'))
                    ->schema([
                        Fieldset::make(__('Final Report Date'))->schema([
                            DatePicker::make('submitted_from')->label(__('From')),
                            DatePicker::make('submitted_until')->label(__('Until')),
                        ])->columns(2),
                    ])
                    ->query(function (Builder $query, array $data) {
                        return $query
                            ->when($data['submitted_from'], fn ($q, $v) => $q->whereDate('final_report_at', '>=', $v))
                            ->when($data['submitted_until'], fn ($q, $v) => $q->whereDate('final_report_at', '<=', $v));
                    })
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];
                        if ($data['submitted_from']) {
                            $indicators[] = __('Submitted from').': '.$data['submitted_from'];
                        }
                        if ($data['submitted_until']) {
                            $indicators[] = __('Submitted until').': '.$data['submitted_until'];
                        }

                        return $indicators;
                    })
                    ->columnSpan(3),

                Filter::make('fees_amount')
                    ->label(__('Fees Amount'))
                    ->schema([
                        Fieldset::make(__('Fees Amount'))->schema([
                            TextInput::make('fees_from')
                                ->label(__('Min Amount'))
                                ->numeric()
                                ->prefix('$')
                                ->placeholder('0.00'),
                            TextInput::make('fees_until')
                                ->label(__('Max Amount'))
                                ->numeric()
                                ->prefix('$')
                                ->placeholder('∞'),
                        ])->columns(2),
                    ])
                    ->query(function (Builder $query, array $data) {
                        return $query
                            ->when($data['fees_from'], fn ($q, $v) => $q->whereHas('fees', fn ($f) => $f->havingRaw('SUM(amount) >= ?', [(float) $v])
                                ->groupBy('matter_id')
                                ->select('matter_id')
                            ))
                            ->when($data['fees_until'], fn ($q, $v) => $q->whereHas('fees', fn ($f) => $f->havingRaw('SUM(amount) <= ?', [(float) $v])
                                ->groupBy('matter_id')
                                ->select('matter_id')
                            ));
                    })
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];
                        if ($data['fees_from']) {
                            $indicators[] = __('Fees min').': $'.number_format((float) $data['fees_from'], 2);
                        }
                        if ($data['fees_until']) {
                            $indicators[] = __('Fees max').': $'.number_format((float) $data['fees_until'], 2);
                        }

                        return $indicators;
                    })
                    ->columnSpan(3),

                // Both plain toggles (no schema), matching the AttentionNeededWidget
                // dashboard stats exactly so each stat's ->url() can link straight
                // into the filtered list behind its own count.
                Filter::make('awaiting_final_report')
                    ->label(__('Awaiting final report'))
                    ->query(fn (Builder $query) => $query
                        ->whereNotNull('final_report_memo_date')
                        ->whereNull('final_report_at')),

                Filter::make('unassigned')
                    ->label(__('Unassigned, still open'))
                    ->query(fn (Builder $query) => $query
                        ->whereDoesntHave('assistantsOnly')
                        ->whereNull('final_report_at')),

            ])
            ->filtersFormColumns(6)
            ->filtersLayout(FiltersLayout::Modal)
            ->filtersFormWidth(Width::FourExtraLarge)
            ->deselectAllRecordsWhenFiltered(true)
            ->recordActions([
                ViewAction::make()->iconButton()->visible(fn ($record) => auth()->user()->can('View:Matter')),
                EditAction::make()->iconButton()->visible(fn ($record) => auth()->user()->can('Update:Matter')),
                DeleteAction::make()->iconButton()->visible(fn ($record) => auth()->user()->can('Delete:Matter')),
                RestoreAction::make()->iconButton()
                    ->visible(fn ($record) => $record->trashed() && auth()->user()->can('Restore:Matter')),
                ForceDeleteAction::make()->iconButton()
                    ->visible(fn ($record) => $record->trashed() && auth()->user()->can('ForceDelete:Matter')),
            ]);
    }

    /**
     * The matters list's search box. The text is split into words on spaces
     * and / - : _ \ | + (searchWords()), then:
     *
     *  - numbers: one is found in the number, the year or a fee amount; two
     *    or more are the reference — (number contains any of them) AND (year
     *    contains any of them) — so "571/2009" and "2009/571" find that
     *    matter, not one whose fee or party happens to hold 2009;
     *  - words: each belongs to the field it is found in (court, type,
     *    party). Words of the same field are OR'd ("دبي الشارقة": either
     *    court), different fields AND'd ("دبي المهاد": Dubai matters of
     *    المهاد). A word found in no field finds nothing.
     *
     * "Found" is always "contains".
     */
    public static function search(Builder $query, string $search): Builder
    {
        $words = self::searchWords($search);
        $numbers = array_values(array_filter($words, fn (string $w): bool => preg_match('/^\d[\d,.]*$/', $w) === 1));
        $texts = array_values(array_diff($words, $numbers));

        if (count($numbers) === 1) {
            $like = '%'.$numbers[0].'%';
            $amount = '%'.str_replace(',', '', $numbers[0]).'%';

            $query->where(fn (Builder $q) => $q
                ->where('number', 'like', $like)
                ->orWhere('year', 'like', $like)
                ->orWhereHas('fees', fn (Builder $f) => $f->where('amount', 'like', $amount)));
        } elseif (count($numbers) > 1) {
            foreach (['number', 'year'] as $column) {
                $query->where(function (Builder $q) use ($column, $numbers) {
                    foreach ($numbers as $number) {
                        $q->orWhere($column, 'like', '%'.$number.'%');
                    }
                });
            }
        }

        // Each word to the fields it is found in; a word in one field only
        // joins that field's OR, one in several is its own OR across them.
        $fields = [
            'court' => fn (string $w): bool => Court::query()->where('name', 'like', '%'.$w.'%')->exists(),
            'type' => fn (string $w): bool => Type::query()->where('name', 'like', '%'.$w.'%')->exists(),
            'party' => fn (string $w): bool => Party::query()->where('name', 'like', '%'.$w.'%')->exists(),
        ];
        $only = ['court' => [], 'type' => [], 'party' => []];
        $several = [];

        foreach ($texts as $word) {
            $in = array_keys(array_filter($fields, fn (callable $found): bool => $found($word)));

            match (count($in)) {
                0 => $query->whereRaw('1 = 0'),
                1 => $only[$in[0]][] = $word,
                default => $several[] = [$word, $in],
            };
        }

        foreach ($only as $field => $fieldWords) {
            if ($fieldWords !== []) {
                $query->where(fn (Builder $q) => self::fieldContains($q, $field, $fieldWords));
            }
        }

        foreach ($several as [$word, $in]) {
            $query->where(function (Builder $q) use ($word, $in) {
                foreach ($in as $field) {
                    $q->orWhere(fn (Builder $q) => self::fieldContains($q, $field, [$word]));
                }
            });
        }

        return $query;
    }

    /**
     * The field contains any of the words.
     *
     * @param  list<string>  $words
     */
    private static function fieldContains(Builder $query, string $field, array $words): void
    {
        $any = function (Builder $q) use ($words): void {
            $q->where(function (Builder $q) use ($words) {
                foreach ($words as $word) {
                    $q->orWhere('name', 'like', '%'.$word.'%');
                }
            });
        };

        match ($field) {
            'court' => $query->whereHas('court', $any),
            'type' => $query->whereHas('type', $any),
            'party' => $query->whereHas('matterParties.party', $any),
        };
    }

    /**
     * The search split into its words.
     *
     * @return list<string>
     */
    public static function searchWords(string $search): array
    {
        $words = preg_split('~[\s/\-:_\\\\|+–]+~u', strtr($search, self::DIGITS), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_filter($words, fn (string $word): bool => ! in_array(mb_strtolower($word), ['لسنة', 'لعام', 'سنة', 'of'], true)));
    }
}
