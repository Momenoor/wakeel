<?php

namespace App\Filament\Mms\Pages;

use App\Enums\FlightTicketStatus;
use App\Filament\Concerns\HasModuleGate;
use App\Filament\Mms\Resources\EmployeeProfiles\EmployeeProfileResource;
use App\Models\FlightTicket;
use App\Support\FlightTicketTable;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

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
                FlightTicketTable::yearColumn(),
                ...FlightTicketTable::detailColumns(),
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
                FlightTicketTable::markPaidAction(fn (): bool => auth()->user()?->can('Update:EmployeeProfile') ?? false),
            ])
            ->emptyStateHeading(__('No flight tickets yet'));
    }
}
