<?php

namespace App\Support;

use App\Models\Court;
use App\Models\Party;
use App\Models\Type;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;

/**
 * The court, matter type and assistant filters the matter reports share —
 * one definition, so a report that adds them gets the same options and the
 * same query as the rest.
 */
class ReportMatterFilters
{
    public static function court(): SelectFilter
    {
        return SelectFilter::make('court_id')
            ->label(__('Court'))
            ->options(fn () => Court::orderBy('name')->pluck('name', 'id'))
            ->searchable()
            ->preload();
    }

    public static function matterType(): SelectFilter
    {
        return SelectFilter::make('type_id')
            ->label(__('Matter Type'))
            ->options(fn () => Type::orderBy('name')->pluck('name', 'id'))
            ->searchable()
            ->preload();
    }

    public static function assistant(): SelectFilter
    {
        return SelectFilter::make('assistant')
            ->label(__('Assistant'))
            ->options(fn () => Party::withRole('expert', 'assistant')
                ->orderBy('name')->pluck('name', 'id'))
            ->searchable()
            ->preload()
            ->query(fn (Builder $query, array $data) => $query->when(
                $data['value'] ?? null,
                fn ($q, $partyId) => $q->whereHas('assistantsOnly', fn ($a) => $a->where('party_id', $partyId))
            ));
    }
}
