<?php

namespace App\Filament\Mms\Resources\EmployeeProfiles\RelationManagers;

use App\Enums\LeaveBalanceEntryKind;
use App\Filament\Concerns\HasRelationManagerPermission;
use App\Models\EmployeeProfile;
use App\Models\LeaveBalanceEntry;
use App\Services\MMS\LeaveBalanceService;
use App\Support\Dates;
use App\Support\ScreenPermissions;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use LogicException;

/**
 * The annual-leave balance, entry by entry: opening balance, 1 January
 * grants, leave taken, and HR's adjustments, each with the balance after it.
 * The balance is their sum.
 */
class LeaveBalanceRelationManager extends RelationManager
{
    use HasRelationManagerPermission;

    public static function viewPermission(): string
    {
        return ScreenPermissions::EMPLOYEE_LEAVE_BALANCE;
    }

    protected static string $relationship = 'leaveBalanceEntries';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Leave Balance');
    }

    public static function getModelLabel(): string
    {
        return __('Leave balance entry');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Leave balance entries');
    }

    public function getRelationship(): Relation
    {
        return $this->profile()->party->leaveBalanceEntries();
    }

    private function profile(): EmployeeProfile
    {
        $record = $this->getOwnerRecord();

        if (! $record instanceof EmployeeProfile) {
            throw new LogicException('This relation manager only attaches to an employee profile.');
        }

        return $record;
    }

    /** @var array<int, float>|null the balance after each entry, read once a request */
    private ?array $balances = null;

    /** @return array<int, float> */
    private function balances(): array
    {
        return $this->balances ??= app(LeaveBalanceService::class)->runningBalances((int) $this->profile()->party_id);
    }

    private function canAdjust(): bool
    {
        return auth()->user()?->can('Update:EmployeeProfile') ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(fn (): string => __('Annual leave balance: :days days', [
                'days' => rtrim(rtrim(number_format(app(LeaveBalanceService::class)->balance((int) $this->profile()->party_id), 1), '0'), '.'),
            ]))
            ->description(__('30 days are added every 1 January; approved annual leave is taken off. Unused days carry over.'))
            ->columns([
                TextColumn::make('entry_date')
                    ->label(__('Date'))
                    ->date()
                    ->sortable(),
                TextColumn::make('kind')
                    ->label(__('Type'))
                    ->badge(),
                TextColumn::make('days')
                    ->label(__('Days'))
                    ->numeric(decimalPlaces: 1)
                    ->color(fn (LeaveBalanceEntry $record): string => (float) $record->days < 0 ? 'danger' : 'success')
                    ->weight('bold'),
                // The balance once this entry was made: in the order they count — the opening balance first, then
                // by date.
                TextColumn::make('balance_after')
                    ->label(__('Balance'))
                    ->state(fn (LeaveBalanceEntry $record): ?float => $this->balances()[$record->getKey()] ?? null)
                    ->numeric(decimalPlaces: 1)
                    ->color(fn (?float $state): ?string => $state !== null && $state < 0 ? 'danger' : null),
                TextColumn::make('note')
                    ->label(__('Note'))
                    ->placeholder('—')
                    ->state(fn (LeaveBalanceEntry $record): ?string => $record->note
                        ?? ($record->leaveRequestPeriod
                            ? __(':from to :to', [
                                'from' => $record->leaveRequestPeriod->start_date?->format(Dates::dateFormat()),
                                'to' => $record->leaveRequestPeriod->end_date?->format(Dates::dateFormat()),
                            ])
                            : null))
                    ->wrap(),
                TextColumn::make('creator.name')
                    ->label(__('By'))
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->modifyQueryUsing(fn ($query) => $query->with(['leaveRequestPeriod', 'creator']))
            // Newest first, the opening balance last: the running balance read upwards.
            ->defaultSort(fn (Builder $query): Builder => $query->orderByRaw('kind = ?', [LeaveBalanceEntryKind::OPENING->value])->orderByDesc('entry_date')->orderByDesc('id'))
            ->headerActions([
                Action::make('adjust')
                    ->label(__('Adjust balance'))
                    ->icon('heroicon-o-adjustments-horizontal')
                    ->visible(fn (): bool => $this->canAdjust())
                    ->schema([
                        TextInput::make('days')
                            ->label(__('Days'))
                            ->numeric()
                            ->step(0.5)
                            ->required()
                            ->notIn(['0'])
                            ->helperText(__('Positive adds days, negative takes them off.')),
                        DatePicker::make('entry_date')
                            ->label(__('Date'))
                            ->default(now())
                            ->required(),
                        TextInput::make('note')
                            ->label(__('Reason'))
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(),
                    ])
                    ->action(function (array $data): void {
                        LeaveBalanceEntry::create([
                            'party_id' => $this->profile()->party_id,
                            'kind' => LeaveBalanceEntryKind::ADJUSTMENT,
                            'days' => (float) $data['days'],
                            'entry_date' => $data['entry_date'],
                            'note' => $data['note'],
                            'created_by' => auth()->id(),
                        ]);
                        $this->balances = null;
                    }),
            ])
            ->recordActions([
                // Only a hand adjustment can be taken back; the rest follow
                // the profile, the yearly grant and the leave requests.
                DeleteAction::make()
                    ->iconButton()
                    ->after(fn () => $this->balances = null)
                    ->visible(fn (LeaveBalanceEntry $record): bool => $this->canAdjust()
                        && $record->kind === LeaveBalanceEntryKind::ADJUSTMENT),
            ])
            ->emptyStateHeading(__('No leave balance entries yet'));
    }
}
