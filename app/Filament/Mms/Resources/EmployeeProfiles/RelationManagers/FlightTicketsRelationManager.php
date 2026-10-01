<?php

namespace App\Filament\Mms\Resources\EmployeeProfiles\RelationManagers;

use App\Enums\FlightTicketStatus;
use App\Filament\Concerns\HasRelationManagerPermission;
use App\Models\EmployeeProfile;
use App\Models\FlightTicket;
use App\Services\MMS\FlightTicketService;
use App\Support\Currency;
use App\Support\ScreenPermissions;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rules\Unique;
use LogicException;
use Throwable;

/**
 * The employee's yearly flight tickets and whether each is paid — through
 * a payroll run or directly.
 */
class FlightTicketsRelationManager extends RelationManager
{
    use HasRelationManagerPermission;

    public static function viewPermission(): string
    {
        return ScreenPermissions::EMPLOYEE_FLIGHT_TICKETS;
    }

    protected static string $relationship = 'flightTickets';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Flight Tickets');
    }

    public static function getModelLabel(): string
    {
        return __('Flight ticket');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Flight Tickets');
    }

    public function getRelationship(): Relation
    {
        return $this->profile()->party->flightTickets();
    }

    private function profile(): EmployeeProfile
    {
        $record = $this->getOwnerRecord();

        if (! $record instanceof EmployeeProfile) {
            throw new LogicException('This relation manager only attaches to an employee profile.');
        }

        return $record;
    }

    private function canManage(): bool
    {
        return auth()->user()?->can('Update:EmployeeProfile') ?? false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('year')
                ->label(__('Year'))
                ->numeric()
                ->minValue(2000)
                ->maxValue(2100)
                ->default(now()->year)
                ->required()
                ->unique(
                    table: 'flight_tickets',
                    column: 'year',
                    ignoreRecord: true,
                    modifyRuleUsing: fn (Unique $rule) => $rule->where('party_id', $this->profile()->party_id),
                ),
            TextInput::make('amount')
                ->label(Currency::label(__('Amount (AED)')))
                ->suffix(Currency::symbol())
                ->numeric()
                ->minValue(0)
                ->step(0.01)
                ->default(fn (): float => (float) $this->profile()->flight_ticket_amount)
                ->required(),
            TextInput::make('note')
                ->label(__('Note'))
                ->maxLength(255)
                ->columnSpanFull(),
        ])->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
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
                TextColumn::make('note')
                    ->label(__('Note'))
                    ->placeholder('—')
                    ->wrap()
                    ->toggleable(),
            ])
            ->modifyQueryUsing(fn ($query) => $query->with('payrollRun'))
            ->defaultSort('year', 'desc')
            ->headerActions([
                CreateAction::make()
                    ->label(__('Add ticket'))
                    ->visible(fn (): bool => $this->canManage()),
            ])
            ->recordActions([
                Action::make('markPaid')
                    ->label(__('Mark as paid'))
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (FlightTicket $record): bool => $this->canManage()
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
                // Only while it is due: a ticket in a run or paid is part of
                // that run's figures.
                EditAction::make()
                    ->iconButton()
                    ->visible(fn (FlightTicket $record): bool => $this->canManage()
                        && $record->status() === FlightTicketStatus::DUE),
                DeleteAction::make()
                    ->iconButton()
                    ->visible(fn (FlightTicket $record): bool => $this->canManage()
                        && $record->status() === FlightTicketStatus::DUE),
            ])
            ->emptyStateHeading(__('No flight tickets yet'));
    }
}
