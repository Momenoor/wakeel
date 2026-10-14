<?php

namespace App\Filament\Mms\Resources\EmployeeProfiles\RelationManagers;

use App\Enums\FlightTicketStatus;
use App\Filament\Concerns\HasRelationManagerPermission;
use App\Models\EmployeeProfile;
use App\Models\FlightTicket;
use App\Support\Currency;
use App\Support\ScreenPermissions;
use App\Support\FlightTicketTable;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Validation\Rules\Unique;
use LogicException;

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
                FlightTicketTable::yearColumn(),
                ...FlightTicketTable::detailColumns(),
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
                FlightTicketTable::markPaidAction(fn (): bool => $this->canManage()),
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
