<?php

namespace App\Support;

use App\Enums\FlightTicketStatus;
use App\Enums\PayrollRunStatus;
use App\Models\FlightTicket;
use App\Models\PayrollRun;
use App\Services\MMS\FlightTicketService;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * The flight-ticket columns and the "Mark as paid" and "Pay with salary"
 * actions shown both on the all-tickets page and on an employee's own tickets tab.
 */
class FlightTicketTable
{
    public static function yearColumn(): TextColumn
    {
        return TextColumn::make('year')
            ->label(__('Year'))
            ->sortable();
    }

    /**
     * Amount, status, payroll run and when/how it was paid.
     *
     * @return list<TextColumn>
     */
    public static function detailColumns(): array
    {
        return [
            TextColumn::make('amount')
                ->label(Currency::label(__('Amount (AED)')))
                ->numeric(decimalPlaces: 2)
                ->description(fn (FlightTicket $record): ?string => $record->is_prorated ? __('pro-rated') : null),
            TextColumn::make('status')
                ->label(__('Status'))
                ->state(fn (FlightTicket $record): FlightTicketStatus => $record->status())
                ->badge(),
            TextColumn::make('payrollRun.period')
                ->label(__('Payroll Run'))
                ->placeholder('—'),
            TextColumn::make('paid_at')
                ->label(__('Paid On'))
                ->date()
                ->placeholder('—')
                ->description(fn (FlightTicket $record): ?string => match ($record->paid_via) {
                    FlightTicket::PAID_VIA_PAYROLL => __('Through payroll'),
                    FlightTicket::PAID_VIA_DIRECT => __('Paid directly'),
                    default => null,
                }),
        ];
    }

    /**
     * @param  Closure(): bool  $canManage  whether the user may mark tickets paid here
     */
    public static function markPaidAction(Closure $canManage): Action
    {
        return Action::make('markPaid')
            ->label(__('Mark as paid'))
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->visible(fn (FlightTicket $record): bool => $canManage()
                && $record->status() === FlightTicketStatus::DUE)
            ->modalHeading(__('Ticket paid outside payroll'))
            ->schema([
                DatePicker::make('paid_at')
                    ->label(__('Paid On'))
                    ->default(now())
                    ->required(),
                TextInput::make('note')
                    ->label(__('Note'))
                    ->maxLength(255),
            ])
            ->action(function (FlightTicket $record, array $data): void {
                try {
                    app(FlightTicketService::class)->markPaid($record, Carbon::parse($data['paid_at']), $data['note'] ?? null);
                } catch (Throwable $exception) {
                    Notification::make()->danger()->title(__('Could not continue'))->body($exception->getMessage())->send();

                    return;
                }

                Notification::make()->success()->title(__('Ticket marked as paid.'))->send();
            });
    }

    /**
     * A due ticket paid with the salaries of the draft payroll run chosen
     * here (FlightTicketService::addToRun()).
     *
     * @param  Closure(): bool  $canManage  whether the user may pay tickets here
     */
    public static function payWithSalaryAction(Closure $canManage): Action
    {
        // The draft runs, read once for the whole table.
        $runs = null;
        $draftRuns = function () use (&$runs): array {
            return $runs ??= PayrollRun::query()
                ->where('status', PayrollRunStatus::DRAFT)
                ->orderByDesc('period')
                ->get()
                ->mapWithKeys(fn (PayrollRun $run): array => [$run->getKey() => $run->period.(filled($run->name) ? ' — '.$run->name : '')])
                ->all();
        };

        return Action::make('payWithSalary')
            ->label(__('Pay with salary'))
            ->icon('heroicon-o-banknotes')
            ->color('info')
            ->visible(fn (FlightTicket $record): bool => $record->status() === FlightTicketStatus::DUE
                && $canManage()
                && (auth()->user()?->can('Generate:PayrollRun') ?? false)
                && $draftRuns() !== [])
            ->modalHeading(__('Pay the ticket with salary'))
            ->modalDescription(__('The ticket is added to the employee\'s payslip in the payroll run chosen, and marked paid when the run is disbursed.'))
            ->schema([
                Select::make('payroll_run_id')
                    ->label(__('Payroll Run'))
                    ->options($draftRuns)
                    ->default(fn (): ?int => array_key_first($draftRuns()))
                    ->required(),
            ])
            ->action(function (FlightTicket $record, array $data): void {
                $run = PayrollRun::findOrFail($data['payroll_run_id']);

                try {
                    app(FlightTicketService::class)->addToRun($record, $run);
                } catch (Throwable $exception) {
                    Notification::make()->danger()->title(__('Could not continue'))->body($exception->getMessage())->send();

                    return;
                }

                Notification::make()->success()->title(__('Ticket added to the :period payroll run.', ['period' => $run->period]))->send();
            });
    }
}
