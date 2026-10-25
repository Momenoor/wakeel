<?php

namespace App\Filament\Mms\Exports;

use App\Enums\FeeType;
use App\Models\Fee;
use App\Models\Matter;
use App\Support\Dates;
use Filament\Actions\Exports\ExportColumn;
use Illuminate\Database\Eloquent\Builder;
use OpenSpout\Writer\XLSX\Options;

class MatterExporter extends MatterSpreadsheetExporter
{
    protected static ?string $model = Matter::class;

    protected function sheetName(): string
    {
        return 'Matters';
    }

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('reference')
                ->label(__('Matter'))
                ->getStateUsing(fn ($record) => $record->year.'/'.$record->number),

            ExportColumn::make('court.name')
                ->label(__('Court')),

            ExportColumn::make('type.name')
                ->label(__('Type')),

            ExportColumn::make('matter_level')
                ->label(__('Level'))
                ->getStateUsing(fn ($record) => $record->level?->getLabel()),

            ...self::dateColumns(),

            ExportColumn::make('parties')
                ->label(__('Parties'))
                ->getStateUsing(fn ($record) => $record->indexedParties
                    ->map(fn ($mp) => sprintf(
                        '%s #%d — %s',
                        __($mp->type ? ucfirst(str_replace('-', ' ', $mp->type)) : ''),
                        $mp->role_index,
                        $mp->party?->name ?? '—'
                    ))
                    ->join("\n")
                ),

            ExportColumn::make('experts')
                ->label(__('Experts'))
                ->getStateUsing(fn ($record) => $record->indexedExperts
                    ->map(fn ($mp) => sprintf(
                        '%s #%d — %s',
                        __($mp->type ? ucfirst(str_replace('-', ' ', $mp->type)) : ''),
                        $mp->role_index,
                        $mp->party?->name ?? '—' // ⚠️ double-check this — should it be $mp->expert->name or $mp->party->name?
                    ))
                    ->join("\n")
                ),

            ExportColumn::make('total_fees_without_vat')
                ->label(__('Total Fees (Without VAT)'))
                ->getStateUsing(fn ($record) => number_format($record->fees->reject(fn ($fee) => $fee->type === FeeType::VAT)->sum('amount'))),

            ExportColumn::make('total_vat')
                ->label(__('Total VAT'))
                // The VAT rows of before, and each fee's own VAT.
                ->getStateUsing(fn ($record) => number_format($record->fees->sum(fn (Fee $fee) => $fee->vatCharged()))),

            ExportColumn::make('total_fees')
                ->label(__('Total'))
                ->getStateUsing(fn ($record) => number_format($record->fees->sum('total'))),

            ExportColumn::make('total_allocations')
                ->label(__('Sum Allocations'))
                ->getStateUsing(fn ($record) => number_format($record->allocations->sum('amount'))),

            ExportColumn::make('unpaid_amount')
                ->label(__('Unpaid'))
                ->getStateUsing(fn ($record) => number_format($record->fees->sum('total') - $record->allocations->sum('amount'))),

            ExportColumn::make('notes')
                ->label(__('Notes'))
                ->getStateUsing(fn ($record) => $record->notes
                    ->map(fn ($note) => $note->text)
                    ->filter()
                    ->join("\n")
                ),

            ExportColumn::make('status')
                ->label(__('Status'))
                ->getStateUsing(fn ($record) => $record->status?->getLabel()),

            ExportColumn::make('collection_status')
                ->label(__('Collection Status'))
                ->getStateUsing(fn ($record) => $record->collection_status?->getLabel()),
        ];
    }

    /**
     * Every date the matter keeps, written as the system writes dates (Settings → General) — a time only where the
     * date has one (the next session, the last change, the deletion).
     *
     * @return list<ExportColumn>
     */
    private static function dateColumns(): array
    {
        $dates = [
            'received_at' => ['Court Assigning Date', false],
            'distributed_at' => ['Assistant Assigning Date', false],
            'next_session_date' => ['Next Session Date', true],
            'initial_report_at' => ['Initial Report Date', false],
            'final_report_at' => ['Final Report Date', false],
            'final_report_memo_date' => ['Final Report Memo Date', false],
            'created_at' => ['Created At', false],
            'updated_at' => ['Updated At', true],
            'deleted_at' => ['Deleted At', true],
        ];

        return array_map(
            fn (string $column, array $date): ExportColumn => ExportColumn::make($column)
                ->label(__($date[0]))
                ->getStateUsing(fn (Matter $record): string => $date[1] ? Dates::dateTime($record->{$column}) : Dates::date($record->{$column})),
            array_keys($dates),
            $dates,
        );
    }

    public function getXlsxWriterOptions(): ?Options
    {
        $options = new Options;
        // In the order of getColumns(): the matter, then its dates, then the rest.
        $widths = [15, 22, 18, 18, ...array_fill(0, count(self::dateColumns()), 18), 40, 40, 22, 14, 14, 18, 14, 30, 14, 20];
        foreach ($widths as $index => $width) {
            $options->setColumnWidth($width, $index + 1);
        }

        return $options;
    }

    public static function modifyQuery(Builder $query): Builder
    {
        return $query->with([
            'court',
            'type',
            'mainPartiesOnly.party',
            'mainPartiesOnly.representatives.party',
            'expertsOnly.party',
            'fees',
            'notes',
            'allocations',
        ]);
    }
}
