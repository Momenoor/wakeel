<?php

namespace App\Support;

use App\Filament\Mms\Resources\Matters\MatterResource;
use App\Models\Matter;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Builder;

/**
 * The matter columns the matter reports share: the reference (year/number,
 * opening the matter), its court with the type under it, and its assistants.
 */
class ReportMatterColumns
{
    /**
     * @param  bool  $joined  the report's query joins other tables: search "matters.number", not "number"
     */
    public static function reference(bool $joined = false): TextColumn
    {
        $prefix = $joined ? 'matters.' : '';

        return TextColumn::make('reference')
            ->label(__('Matter'))
            ->getStateUsing(fn (Matter $record) => $record->year.'/'.$record->number)
            ->url(fn (Matter $record) => MatterResource::getUrl('view', ['record' => $record]))
            ->weight('bold')
            ->searchable(query: fn (Builder $query, string $search) => $query->where(
                fn ($q) => $q->where($prefix.'number', 'like', "%{$search}%")
                    ->orWhere($prefix.'year', 'like', "%{$search}%")
            ));
    }

    public static function courtAndType(): TextColumn
    {
        return TextColumn::make('court.name')
            ->label(__('Court / Type'))
            ->description(fn (Matter $record) => $record->type?->name)
            ->wrap();
    }

    public static function assistants(): TextColumn
    {
        return TextColumn::make('assistants')
            ->label(__('Assistant'))
            ->getStateUsing(fn (Matter $record) => $record->assistantsOnly
                ->map(fn ($mp) => $mp->party?->name)->filter()->implode(', ') ?: '—')
            ->wrap();
    }
}
