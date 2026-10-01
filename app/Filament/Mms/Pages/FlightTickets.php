<?php

namespace App\Filament\Mms\Pages;

use App\Enums\FlightTicketStatus;
use App\Filament\Concerns\HasModuleGate;
use App\Filament\Mms\Resources\EmployeeProfiles\EmployeeProfileResource;
use App\Models\FlightTicket;
use App\Services\MMS\FlightTicketService;
use App\Support\Currency;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Every employee's flight tickets in one list — what is due, what is in a
 * payroll run, what has been paid and how.
 */
class FlightTickets extends Page implements HasTable
{
    use HasModuleGate;
    use InteractsWithTable;

    protected string $view = 'filament.pages.flight-tickets';

    protected static string|null|BackedEnum $navigationIcon = 'heroicon-o-paper-airplane';

    protected static ?int $navigationSort = 4;

    public static function moduleGateKey(): string
    {
        return 'mms_payroll';
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Human Resources');
    }

    public static function getNavigationLabel(): string
    {
        return __('Flight Tickets');
    }

    public function getTitle(): string
    {
        return __('Flight Tickets');
    }

    public static function canAccess(): bool
    {
        return static::isModuleEnabled() && (auth()->user()?->can('View:FlightTickets') ?? false);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(FlightTicket::query()->with(['party.employeeProfile', 'payrollRun']))
            ->columns([
                TextColumn::make('party.name')
                    ->label(__('Employee'))
                    ->searchable()
                    ->sortable()
                    ->url(fn (FlightTicket $record): ?string => $record->party?->employeeProfile
                        ? EmployeeProfileResource::getUrl('edit', ['record' => $record->party->employeeProfile])
                        : null),
                TextColumn::make('year')
                    ->label(__('Year'))
                    ->sortable(),
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
                    ->date('d/m/Y')
                    ->placeholder('—')
                    ->description(fn (FlightTicket $record): ?string => match ($record->paid_via) {
                        FlightTicket::PAID_VIA_PAYROLL => __('Through payroll'),
                        FlightTicket::PAID_VIA_DIRECT => __('Paid directly'),
                        default => null,
                    }),
            ])
            ->defaultSort('year', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->label(__('Status'))
                    ->options(FlightTicketStatus::class)
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        FlightTicketStatus::DUE->value => $query->due(),
                        FlightTicketStatus::IN_PAYROLL->value => $query->whereNull('paid_at')->whereNotNull('payroll_run_id'),
                        FlightTicketStatus::PAID->value => $query->whereNotNull('paid_at'),
                        default => $query,
                    }),
                SelectFilter::make('year')
                    ->label(__('Year'))
                    ->options(fn (): array => FlightTicket::query()->distinct()->orderByDesc('year')->pluck('year', 'year')->all()),
            ])
            ->recordActions([
                Action::make('markPaid')
                    ->label(__('Mark as paid'))
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (FlightTicket $record): bool => (auth()->user()?->can('Update:EmployeeProfile') ?? false)
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
                    }),
            ])
            ->emptyStateHeading(__('No flight tickets yet'));
    }
}
