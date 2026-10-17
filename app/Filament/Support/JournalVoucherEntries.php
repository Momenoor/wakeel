<?php

namespace App\Filament\Support;

use App\Support\Currency;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Text;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;

/**
 * A payroll journal voucher on screen — a run's (its details window, from
 * the runs list or the run) or the year's EOSG closing one — and the EOSG
 * balances per employee shown beside the latter.
 */
class JournalVoucherEntries
{
    /**
     * The voucher, formatted for keying into QuickBooks by hand.
     *
     * There is no QuickBooks integration and this does not pretend to be one:
     * the office types the entry in, so what matters is that the two columns
     * are easy to read off and that the totals visibly agree.
     *
     * @param  array{period: string, employee_count: int, debits: list<array{account: string, detail: string|null, amount: float}>, credits: list<array{account: string, detail: string|null, amount: float}>, total_debit: float, total_credit: float, balanced: bool}  $voucher
     * @return list<Component>
     */
    public static function voucher(array $voucher): array
    {
        // Loan clearing is itemised per employee (its detail), since the
        // loan ledger it reconciles against is.
        $line = fn (array $line, bool $credit): array => [
            'account' => __($line['account']).($line['detail'] ? ' — '.$line['detail'] : ''),
            'credit_side' => $credit,
            'debit' => $credit ? null : $line['amount'],
            'credit' => $credit ? $line['amount'] : null,
        ];

        return [
            Grid::make(2)->schema([
                TextEntry::make('period')->label(__('Period'))->state($voucher['period'])->weight(FontWeight::SemiBold),
                TextEntry::make('employee_count')->label(__('Employees'))->state($voucher['employee_count'])->weight(FontWeight::SemiBold),
            ]),
            EntryTable::make('voucher_lines', [
                TextEntry::make('account')
                    ->label(__('Account'))
                    ->weight(EntryTable::totalWeight(...))
                    // Indented the way a paper voucher indents its credit side.
                    ->extraAttributes(fn (TextEntry $component): array => (EntryTable::row($component)['credit_side'] ?? false) ? ['class' => 'ps-6'] : []),
                EntryTable::amount('debit', Currency::label(__('Debit').' (AED)'))->placeholder('—'),
                EntryTable::amount('credit', Currency::label(__('Credit').' (AED)'))->placeholder('—'),
            ])->state([
                ...array_map(fn (array $debit): array => $line($debit, false), $voucher['debits']),
                ...array_map(fn (array $credit): array => $line($credit, true), $voucher['credits']),
                ['total' => true, 'account' => __('Total'), 'debit' => $voucher['total_debit'], 'credit' => $voucher['total_credit']],
            ]),
            // An unbalanced voucher is stated rather than silently swallowed:
            // it means a payslip line is missing an account, and keying it in
            // as-is would leave QuickBooks refusing the entry with no
            // explanation.
            Text::make($voucher['balanced']
                ? __('Debits and credits agree.')
                : __('This voucher does not balance. Regenerate the run before posting it.'))
                ->color($voucher['balanced'] ? 'success' : 'danger')
                ->weight($voucher['balanced'] ? null : FontWeight::SemiBold)
                ->size(TextSize::ExtraSmall),
        ];
    }

    /**
     * Opening / current year / closing balance per employee — purely
     * informational. The voucher itself still posts only the current year's
     * movement; this shows the running total behind that movement, and
     * whether it has actually been paid out, without opening each
     * employee's file.
     *
     * @param  list<array{party_name: string, opening_balance: float, current_year_amount: float, closing_balance: float, left_during_year: bool, date_of_leaving: string|null, paid_amount: float, outstanding: float, payment_status: string}>  $rows
     */
    public static function rollforward(array $rows): RepeatableEntry
    {
        $amounts = ['opening_balance', 'current_year_amount', 'closing_balance', 'paid_amount', 'outstanding'];

        return EntryTable::make('rollforward', [
            TextEntry::make('party_name')
                ->label(__('Employee'))
                ->formatStateUsing(fn (string $state, TextEntry $component): string => ($row = EntryTable::row($component))['left_during_year'] ?? false
                    ? $state.' — '.__('left :date', ['date' => $row['date_of_leaving']])
                    : $state)
                ->weight(EntryTable::totalWeight(...)),
            EntryTable::amount('opening_balance', Currency::label(__('Opening Balance').' (AED)')),
            EntryTable::amount('current_year_amount', Currency::label(__('Current Year Amount').' (AED)')),
            EntryTable::amount('closing_balance', Currency::label(__('Closing Balance').' (AED)'))
                ->weight(fn (TextEntry $component): FontWeight => EntryTable::totalWeight($component) ?? FontWeight::SemiBold),
            EntryTable::amount('paid_amount', Currency::label(__('Paid').' (AED)')),
            EntryTable::amount('outstanding', Currency::label(__('Outstanding').' (AED)')),
            TextEntry::make('payment_status')
                ->label(__('Status'))
                ->alignCenter()
                ->color(fn (string $state): string => match ($state) {
                    __('Paid in Full') => 'success',
                    __('Partially Paid') => 'warning',
                    default => 'gray',
                })
                ->weight(fn (string $state): ?FontWeight => in_array($state, [__('Paid in Full'), __('Partially Paid')], true) ? FontWeight::SemiBold : null),
        ])->state([
            ...$rows,
            [
                'total' => true,
                'party_name' => __('Total'),
                ...collect($amounts)->mapWithKeys(fn (string $key): array => [$key => array_sum(array_column($rows, $key))])->all(),
            ],
        ]);
    }
}
