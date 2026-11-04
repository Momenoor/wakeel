<?php

namespace App\Filament\Mms\Resources\Companies\RelationManagers;

use App\Filament\Mms\Resources\PayrollRuns\PayrollRunResource;
use App\Filament\Mms\Resources\PayrollRuns\Tables\PayrollRunsTable;
use App\Models\PayrollRun;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A company's payroll runs, one a month: the employees it sponsors, paid with its own details on the Salary
 * Authorization Form and the journal voucher. Each opens on its own page; a new one starts with the company chosen.
 * Seen by whoever may see payroll runs (and, when the salary screens are kept to some users, by them alone).
 */
class PayrollRunsRelationManager extends RelationManager
{
    protected static string $relationship = 'payrollRuns';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Payroll Runs');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return PayrollRunResource::canAccess() && PayrollRunResource::canViewAny();
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('period')
            ->modifyQueryUsing(fn (Builder $query): Builder => PayrollRunsTable::withTotals($query))
            ->columns(PayrollRunsTable::columns())
            ->defaultSort('period', 'desc')
            ->recordUrl(fn (PayrollRun $record): ?string => PayrollRunResource::canView($record) ? PayrollRunResource::getUrl('view', ['record' => $record]) : null)
            ->headerActions([
                Action::make('newRun')
                    ->label(__('New Payroll Run'))
                    ->icon('heroicon-o-plus')
                    ->visible(fn (): bool => PayrollRunResource::canCreate())
                    ->url(fn (): string => PayrollRunResource::getUrl('create', ['company' => $this->getOwnerRecord()->getKey()])),
            ])
            ->recordActions(PayrollRunsTable::documentActions())
            ->emptyStateHeading(__('No payroll runs yet'))
            ->emptyStateIcon('heroicon-o-calculator');
    }
}
