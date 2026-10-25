<?php

namespace App\Filament\Mms\Exports;

use App\Enums\FeeType;
use App\Models\Fee;
use App\Models\Matter;
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

    public function getXlsxWriterOptions(): ?Options
    {
        $options = new Options;
        $options->setColumnWidth(15, 1);  // Matter
        $options->setColumnWidth(22, 2);  // Court
        $options->setColumnWidth(18, 3);  // Type
        $options->setColumnWidth(18, 4);  // Level
        $options->setColumnWidth(40, 5);  // Parties
        $options->setColumnWidth(40, 6);  // Experts
        $options->setColumnWidth(22, 7);  // Total Fees (Without VAT)
        $options->setColumnWidth(14, 8);  // Total VAT
        $options->setColumnWidth(14, 9);  // Total
        $options->setColumnWidth(18, 10); // Sum Allocations
        $options->setColumnWidth(14, 11); // Unpaid
        $options->setColumnWidth(30, 12); // Notes
        $options->setColumnWidth(14, 13); // Status
        $options->setColumnWidth(20, 14); // Collection Status

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
