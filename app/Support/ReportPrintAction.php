<?php

namespace App\Support;

use Filament\Actions\Action;
use Filament\Tables\Contracts\HasTable;

/**
 * A plain browser print of the current report — no per-report print route or
 * blade view to maintain. The action executes client-side via Alpine click handler
 * (window.print()).
 * What gets hidden from the printed page (sidebar, topbar, filters, action
 * buttons) is handled once, panel-wide, by the @media print rules in
 * resources/css/filament/admin/theme.css.
 *
 * A report paged on screen still prints whole: the table switches to "All"
 * rows, prints, then goes back to the page size the reader had.
 */
class ReportPrintAction
{
    /**
     * Shows every row, prints, then restores the page size.
     */
    private const PRINT_ALL = <<<'JS'
        (async () => {
            const before = $wire.tableRecordsPerPage;
            if (before !== 'all') {
                await $wire.set('tableRecordsPerPage', 'all');
                await new Promise((resolve) => setTimeout(resolve, 400));
            }
            window.print();
            if (before !== 'all') {
                $wire.set('tableRecordsPerPage', before);
            }
        })()
        JS;

    public static function make(): Action
    {
        return Action::make('print')
            ->label(__('Print'))
            ->icon('heroicon-o-printer')
            ->color('gray')
            ->alpineClickHandler(fn ($livewire): string => self::printsAllPages($livewire) ? self::PRINT_ALL : 'window.print()');
    }

    /**
     * Whether the report is paged on screen with an "All" option to print.
     */
    public static function printsAllPages(mixed $livewire): bool
    {
        if (! $livewire instanceof HasTable) {
            return false;
        }

        $table = $livewire->getTable();

        return $table->isPaginated() && in_array('all', $table->getPaginationPageOptions(), true);
    }
}
