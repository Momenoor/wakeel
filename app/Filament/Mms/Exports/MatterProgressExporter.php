<?php

namespace App\Filament\Mms\Exports;

use App\Models\MatterProgress;
use App\Support\Dates;
use Filament\Actions\Exports\ExportColumn;
use Illuminate\Database\Eloquent\Builder;

/**
 * A matter's progress (its Progress tab) as a sheet: each step's date, type, name and details, who added it or
 * that Wakeel recorded it, and the periodic report it went in. The rows are those the tab shows — its filters,
 * search and order.
 */
class MatterProgressExporter extends MatterSpreadsheetExporter
{
    protected static ?string $model = MatterProgress::class;

    protected function sheetName(): string
    {
        return 'Progress';
    }

    public static function modifyQuery(Builder $query): Builder
    {
        return $query->with(['matter:id,year,number', 'user:id,name', 'periodicReport:id,number']);
    }

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('matter')
                ->label(__('Matter'))
                ->getStateUsing(fn (MatterProgress $record): string => $record->matter->year.'/'.$record->matter->number),

            ExportColumn::make('happened_at')
                ->label(__('Date'))
                // As the system writes dates, the time only when there is one.
                ->getStateUsing(fn (MatterProgress $record): string => match (true) {
                    $record->happened_at === null => '',
                    $record->happened_at->format('H:i') === '00:00' => Dates::date($record->happened_at),
                    default => Dates::dateTime($record->happened_at),
                }),

            ExportColumn::make('type')
                ->label(__('Type'))
                ->getStateUsing(fn (MatterProgress $record): ?string => $record->type?->getLabel()),

            ExportColumn::make('title')
                ->label(__('Name')),

            ExportColumn::make('details')
                ->label(__('Details')),

            ExportColumn::make('user.name')
                ->label(__('By')),

            ExportColumn::make('automatic')
                ->label(__('Automatic'))
                ->getStateUsing(fn (MatterProgress $record): string => $record->isAutomatic() ? __('Yes') : __('No')),

            ExportColumn::make('periodicReport.number')
                ->label(__('Periodic report'))
                ->getStateUsing(fn (MatterProgress $record): string => $record->periodicReport ? __('Report no. :number', ['number' => $record->periodicReport->number]) : ''),
        ];
    }
}
