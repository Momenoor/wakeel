<?php

namespace App\Support;

use BackedEnum;
use Carbon\CarbonInterface;
use Filament\Actions\Action;
use Filament\Support\Contracts\HasLabel;
use Filament\Tables\Columns\Column;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Any report table straight to an Excel file — exactly what's on screen:
 * the visible columns, every row the current filters and sort select (not
 * just the current page), each cell as the column shows it. No exporter
 * class per report, and no queue: the file is built in the request and
 * downloaded at once.
 */
class ReportExcelAction
{
    public static function make(): Action
    {
        return Action::make('exportExcel')
            ->label(__('Excel'))
            ->icon('heroicon-o-arrow-down-tray')
            ->color('gray')
            ->action(fn (HasTable $livewire) => self::download($livewire));
    }

    public static function download(HasTable $livewire): BinaryFileResponse
    {
        $table = $livewire->getTable();
        $columns = array_values(array_filter(
            $table->getVisibleColumns(),
            fn (Column $column) => ! $column->isHidden(),
        ));

        $title = method_exists($livewire, 'getTitle') ? (string) $livewire->getTitle() : __('Report');
        $path = storage_path('app/temp/'.Str::uuid().'.xlsx');

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        $writer = new Writer;
        $writer->openToFile($path);

        if (app()->getLocale() === 'ar') {
            $writer->getCurrentSheet()->setSheetView((new SheetView)->setRightToLeft(true));
        }

        $writer->addRow(Row::fromValues(
            array_map(fn (Column $column) => strip_tags((string) $column->getLabel()), $columns),
            (new Style)->setFontBold(),
        ));

        $livewire->getFilteredSortedTableQuery()->get()->each(function ($record) use ($writer, $columns, $livewire) {
            $writer->addRow(Row::fromValues(array_map(
                fn (Column $column) => self::cell(
                    $column->record($record)->recordKey($livewire->getTableRecordKey($record))->getState(),
                    $column,
                ),
                $columns,
            )));
        });

        $writer->close();

        return response()
            ->download($path, (Str::slug($title) ?: 'report').'-'.now()->format('Y-m-d').'.xlsx')
            ->deleteFileAfterSend();
    }

    /**
     * Numbers stay numbers (so Excel can sum them), dates become
     * dd/mm/yyyy, enums their label, lists a comma-separated line.
     */
    private static function cell(mixed $state, Column $column): string|int|float|null
    {
        if ($state instanceof Collection) {
            $state = $state->all();
        }

        if (is_array($state)) {
            return implode(', ', array_map(fn ($value) => (string) self::cell($value, $column), $state));
        }

        return match (true) {
            $state === null => null,
            $state instanceof HasLabel => (string) $state->getLabel(),
            $state instanceof BackedEnum => (string) $state->value,
            $state instanceof CarbonInterface => $state->format('d/m/Y'),
            is_bool($state) => $state ? __('Yes') : __('No'),
            is_int($state), is_float($state) => $state,
            is_numeric($state) => (float) $state,
            default => strip_tags((string) $column->formatState($state)),
        };
    }
}
