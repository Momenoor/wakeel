<?php

namespace App\Filament\Mms\Resources\EmployeeProfiles\Tables;

use App\Filament\Support\ExpiringDocuments;
use App\Models\EmployeeProfile;
use App\Support\Currency;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class EmployeeProfilesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with('photo')
                ->withSum('leaveBalanceEntries as leave_balance', 'days'))
            ->columns([
                // Their latest personal picture (Documents), opened through the permission check.
                ImageColumn::make('photo')
                    ->label('')
                    ->circular()
                    ->imageSize(36)
                    ->checkFileExistence(false)
                    ->state(fn (EmployeeProfile $record): ?string => $record->photo?->isPreviewable() && strtolower((string) $record->photo->extension) !== 'pdf'
                        ? $record->photo->url(inline: true)
                        : null),
                TextColumn::make('party.name')
                    ->label(__('Employee'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('employee_no')
                    ->label(__('Employee Number'))
                    ->searchable()
                    ->sortable()
                    ->placeholder('—'),
                TextColumn::make('designation')
                    ->label(__('Designation'))
                    ->searchable()
                    ->placeholder('—'),
                TextColumn::make('date_of_joining')
                    ->label(__('Date of Joining'))
                    ->date()
                    ->sortable(),
                // One column for the four expiry dates: a badge each for one expired (red) or due soon (amber).
                ExpiringDocuments::column(EmployeeProfile::EXPIRING),
                TextColumn::make('date_of_leaving')
                    ->label(__('Date of Leaving'))
                    ->date()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('leave_balance')
                    ->label(__('Leave Balance'))
                    ->numeric(decimalPlaces: 1)
                    ->suffix(' '.__('days'))
                    ->placeholder('0')
                    ->sortable()
                    ->color(fn ($state): ?string => (float) $state <= 0 ? 'danger' : null),
            ])
            ->defaultSort('date_of_joining', 'desc')
            ->filters([
                TernaryFilter::make('employed')
                    ->label(__('Currently Employed'))
                    ->placeholder(__('All'))
                    ->trueLabel(__('Currently Employed'))
                    ->falseLabel(__('Left the firm'))
                    ->queries(
                        true: fn (Builder $query) => $query->whereNull('date_of_leaving'),
                        false: fn (Builder $query) => $query->whereNotNull('date_of_leaving'),
                        blank: fn (Builder $query) => $query,
                    )
                    ->default(true),
                ExpiringDocuments::filter(EmployeeProfile::EXPIRING),
            ])
            ->recordActions([
                EditAction::make()->iconButton(),
                DeleteAction::make()->iconButton(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    self::flightTicketBulkAction(),
                    self::toggleBulkAction(
                        'salary_form',
                        'include_in_salary_authorization_form',
                        __('Salary Authorization Form'),
                        __('Include in Salary Authorization Form'),
                        'heroicon-o-document-text',
                    ),
                    self::toggleBulkAction(
                        'eosg',
                        'is_eosg_applicable',
                        __('EOSG'),
                        __('Applicable for EOSG'),
                        'heroicon-o-banknotes',
                    ),
                    BulkAction::make('mark_left')
                        ->label(__('Mark as left'))
                        ->icon('heroicon-o-arrow-right-start-on-rectangle')
                        ->color('warning')
                        ->visible(fn (): bool => self::canUpdate())
                        ->schema([
                            DatePicker::make('date_of_leaving')
                                ->label(__('Date of Leaving'))
                                ->default(now())
                                ->required(),
                        ])
                        ->action(fn (Collection $records, array $data) => self::updateEach($records, ['date_of_leaving' => $data['date_of_leaving']]))
                        ->deselectRecordsAfterCompletion(),
                    DeleteBulkAction::make()
                        ->visible(fn (): bool => auth()->user()?->can('Delete:EmployeeProfile') ?? false)
                        ->authorize(fn (): bool => auth()->user()?->can('Delete:EmployeeProfile') ?? false),
                ]),
            ])
            ->emptyStateHeading(__('No employee records yet'))
            ->emptyStateActions([
                CreateAction::make()->label(__('Add Employee')),
            ])
            ->defaultSort('employee_no');
    }

    private static function canUpdate(): bool
    {
        return auth()->user()?->can('Update:EmployeeProfile') ?? false;
    }

    /**
     * Saved one by one, so each change is logged and its model events run.
     *
     * @param  Collection<int, EmployeeProfile>  $records
     * @param  array<string, mixed>  $attributes
     */
    private static function updateEach(Collection $records, array $attributes): void
    {
        $records->each(fn (EmployeeProfile $profile) => $profile->update($attributes));

        Notification::make()
            ->success()
            ->title(__(':count employees updated.', ['count' => $records->count()]))
            ->send();
    }

    /**
     * Flight ticket entitlement for the selected employees — the amount is
     * set too when one is given, otherwise each keeps their own.
     */
    private static function flightTicketBulkAction(): BulkAction
    {
        return BulkAction::make('flight_ticket')
            ->label(__('Flight Ticket'))
            ->icon('heroicon-o-paper-airplane')
            ->visible(fn (): bool => self::canUpdate())
            ->schema([
                Toggle::make('flight_ticket_entitled')
                    ->label(__('Entitled to a yearly flight ticket'))
                    ->default(true)
                    ->live(),
                TextInput::make('flight_ticket_amount')
                    ->label(Currency::label(__('Yearly Ticket Amount (AED)')))
                    ->suffix(Currency::symbol())
                    ->numeric()
                    ->minValue(0)
                    ->step(0.01)
                    ->helperText(__('Leave empty to keep each employee\'s current amount.'))
                    ->visible(fn (Get $get): bool => (bool) $get('flight_ticket_entitled')),
            ])
            ->action(function (Collection $records, array $data): void {
                $attributes = ['flight_ticket_entitled' => (bool) $data['flight_ticket_entitled']];

                if ($attributes['flight_ticket_entitled'] && filled($data['flight_ticket_amount'] ?? null)) {
                    $attributes['flight_ticket_amount'] = (float) $data['flight_ticket_amount'];
                }

                self::updateEach($records, $attributes);
            })
            ->deselectRecordsAfterCompletion();
    }

    /**
     * Switch a yes/no setting on or off for the selected employees.
     */
    private static function toggleBulkAction(string $name, string $column, string $label, string $toggleLabel, string $icon): BulkAction
    {
        return BulkAction::make($name)
            ->label($label)
            ->icon($icon)
            ->visible(fn (): bool => self::canUpdate())
            ->schema([
                Toggle::make('value')
                    ->label($toggleLabel)
                    ->default(true),
            ])
            ->action(fn (Collection $records, array $data) => self::updateEach($records, [$column => (bool) $data['value']]))
            ->deselectRecordsAfterCompletion();
    }
}
