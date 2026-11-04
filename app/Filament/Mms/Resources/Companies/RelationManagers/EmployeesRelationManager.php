<?php

namespace App\Filament\Mms\Resources\Companies\RelationManagers;

use App\Filament\Mms\Resources\EmployeeProfiles\EmployeeProfileResource;
use App\Filament\Support\ExpiringDocuments;
use App\Models\Company;
use App\Models\EmployeeProfile;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The employees a company sponsors — their visas under its establishment card — with their documents' expiry,
 * for whoever may see employees; whoever may change them adds employees to it, or takes one off it.
 */
class EmployeesRelationManager extends RelationManager
{
    protected static string $relationship = 'employees';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Sponsored employees');
    }

    public static function getModelLabel(): string
    {
        return __('Employee');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Employees');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('viewAny', EmployeeProfile::class) ?? false;
    }

    public function isReadOnly(): bool
    {
        // Its buttons answer to the employees' permissions, not to the company's page.
        return false;
    }

    private static function canChange(): bool
    {
        return auth()->user()?->can('Update:EmployeeProfile') ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('employee_no')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('party'))
            ->columns([
                TextColumn::make('party.name')
                    ->label(__('Employee'))
                    ->searchable()
                    ->sortable()
                    ->url(fn (EmployeeProfile $record): ?string => auth()->user()?->can('update', $record) ? EmployeeProfileResource::getUrl('edit', ['record' => $record]) : null),
                TextColumn::make('employee_no')
                    ->label(__('Employee Number'))
                    ->searchable()
                    ->placeholder('—'),
                TextColumn::make('designation')
                    ->label(__('Designation'))
                    ->placeholder('—'),
                TextColumn::make('residency_visa_no')
                    ->label(__('Visa No'))
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('residency_expiry')
                    ->label(__('Residency Expiry'))
                    ->date()
                    ->sortable()
                    ->placeholder('—'),
                ExpiringDocuments::column(EmployeeProfile::EXPIRING),
            ])
            ->defaultSort('residency_expiry')
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
            ])
            ->headerActions([
                Action::make('addEmployees')
                    ->label(__('Add employees'))
                    ->icon('heroicon-o-user-plus')
                    ->visible(fn (): bool => self::canChange())
                    ->schema([
                        Select::make('employees')
                            ->label(__('Employees'))
                            ->multiple()
                            ->searchable()
                            ->required()
                            // Those on staff not sponsored by it already; one sponsored by another company moves here.
                            ->options(fn (): array => EmployeeProfile::query()
                                ->whereNull('date_of_leaving')
                                ->where(fn (Builder $query) => $query->whereNull('company_id')->orWhere('company_id', '!=', $this->getOwnerRecord()->getKey()))
                                ->with('party')
                                ->get()
                                ->mapWithKeys(fn (EmployeeProfile $profile): array => [$profile->getKey() => trim(($profile->party?->name ?? '').($profile->employee_no ? ' — '.$profile->employee_no : ''))])
                                ->sort()
                                ->all()),
                    ])
                    ->action(function (array $data): void {
                        $company = $this->company();

                        // One by one, so each change is logged and the sponsor's name follows.
                        EmployeeProfile::query()->whereKey($data['employees'])->get()
                            ->each(fn (EmployeeProfile $profile) => $profile->update(['company_id' => $company->getKey()]));

                        Notification::make()->success()->title(__(':count employees updated.', ['count' => count($data['employees'])]))->send();
                    }),
            ])
            ->recordActions([
                Action::make('removeSponsor')
                    ->label(__('Not sponsored by this company'))
                    ->icon('heroicon-o-user-minus')
                    ->iconButton()
                    ->color('danger')
                    ->visible(fn (): bool => self::canChange())
                    ->requiresConfirmation()
                    ->action(fn (EmployeeProfile $record) => $record->update(['company_id' => null, 'sponsor_name' => null])),
            ])
            ->emptyStateHeading(__('No employees sponsored by this company yet'))
            ->emptyStateIcon('heroicon-o-users');
    }

    private function company(): Company
    {
        /** @var Company */
        return $this->getOwnerRecord();
    }
}
