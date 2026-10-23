<?php

namespace App\Support;

use App\Enums\FlightTicketStatus;
use App\Models\FlightTicket;
use App\Services\MMS\FlightTicketService;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * The flight-ticket columns and the "Mark as paid" action shown both on the
 * all-tickets page and on an employee's own tickets tab.
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
}
