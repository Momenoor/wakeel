<?php

namespace App\Filament\Concerns;

use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Tables\Filters\Indicator;

/**
 * A report page: its table, printed on its own — landscape, with a header
 * (the report's name, who printed it and when, the filters applied) and
 * nothing of the panel around it.
 */
trait PrintableReport
{
    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Group::make([
                    // The print rules, and the header seen only on paper.
                    View::make('filament.pages.reports.partials.report-print-styles'),
                    View::make('filament.pages.reports.partials.report-print-header')
                        ->viewData(fn (): array => [
                            'title' => $this->getTitle(),
                            'indicators' => array_map(
                                fn ($indicator): string => $indicator instanceof Indicator ? (string) $indicator->getLabel() : (string) $indicator,
                                $this->getTable()->getFilterIndicators(),
                            ),
                        ]),
                    ...$this->reportHeaderComponents(),
                    EmbeddedTable::make(),
                ])->extraAttributes(['class' => 'report-print-container w-full']),
            ]);
    }

    /**
     * "All" rows is for printing: a report opened again starts on its page size, not loading every row —
     * remembered from a print whose way back never arrived, it made a 31 MB page every visit.
     */
    public function bootPrintableReport(): void
    {
        // This page's tables' remembered page sizes (Filament keeps them as "tables.<md5 of the class>…_per_page"):
        // read without the table, which is not made yet.
        $mine = md5(static::class);

        foreach ((array) session('tables', []) as $key => $value) {
            if ($value === 'all' && str_starts_with((string) $key, $mine) && str_ends_with((string) $key, '_per_page')) {
                session()->forget('tables.'.$key);
            }
        }
    }

    /** Built again when drawn — after the filters are applied, not before (see RendersFreshContent). */
    public function renderingPrintableReport(): void
    {
        unset($this->cachedSchemas['content']);
    }

    /**
     * What a report shows between its print header and its table (a summary, say) — printed with them.
     *
     * @return array<Component>
     */
    protected function reportHeaderComponents(): array
    {
        return [];
    }
}
