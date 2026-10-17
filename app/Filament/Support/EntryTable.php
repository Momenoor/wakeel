<?php

namespace App\Filament\Support;

use Filament\Infolists\Components\Entry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Component;
use Filament\Support\Enums\FontWeight;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Rows shown as a table in a details window or on a page — a repeatable
 * entry laid out as a table. Each column is headed by its entry's label, so
 * a narrow screen (where the cells stack) still names each; an entry with
 * its label hidden has a column with no heading.
 *
 * Rows given as arrays can end with a totals row ('total' => true), shown
 * in bold.
 */
class EntryTable
{
    /**
     * @param  array<int|string, Component>  $entries  one per column, in order; a column of
     *                                                 several (a group) is keyed by its heading
     */
    public static function make(string $name, array $entries): RepeatableEntry
    {
        $columns = [];

        foreach ($entries as $heading => $entry) {
            $columns[] = $entry instanceof Entry
                ? TableColumn::make(fn (): string|Htmlable => $entry->getLabel() ?? '')
                    ->alignment(fn () => $entry->getAlignment())
                    ->hiddenHeaderLabel(fn (): bool => $entry->isLabelHidden())
                : TableColumn::make((string) $heading);
        }

        return RepeatableEntry::make($name)
            ->hiddenLabel()
            ->table($columns)
            ->schema(array_values($entries));
    }

    /** An amount, to two decimals, at the end of its cell. */
    public static function amount(string $name, string|Htmlable $label): TextEntry
    {
        return TextEntry::make($name)
            ->label($label)
            ->alignEnd()
            ->formatStateUsing(fn ($state): string => number_format((float) $state, 2))
            ->weight(self::totalWeight(...))
            ->extraAttributes(['class' => 'tabular-nums']);
    }

    /**
     * The row (given as an array) a cell is in — for what a cell shows
     * that depends on the rest of its row.
     *
     * @return array<string, mixed>
     */
    public static function row(Component $cell): array
    {
        $item = $cell->getContainer();

        $row = data_get($item->getParentComponent()?->getState(), (string) $item->getStatePath(isAbsolute: false));

        return is_array($row) ? $row : [];
    }

    /** Whether a cell is in the totals row. */
    public static function isTotal(Component $cell): bool
    {
        return (bool) (self::row($cell)['total'] ?? false);
    }

    /** Bold in the totals row. */
    public static function totalWeight(Component $component): ?FontWeight
    {
        return self::isTotal($component) ? FontWeight::Bold : null;
    }
}
