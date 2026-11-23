<?php

namespace App\Filament\Mms\Resources\EmployeeLoans\RelationManagers;

use App\Enums\LoanStatus;
use App\Filament\Concerns\HasRelationManagerPermission;
use App\Filament\Mms\Concerns\RefreshesPayrollData;
use App\Models\LoanInstallment;
use App\Services\MMS\LoanScheduleService;
use App\Support\Currency;
use App\Support\ScreenPermissions;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * The instalment schedule, read-only.
 *
 * Amounts are frozen when the schedule is built. Editing one here would let a
 * later change shift every remaining instalment without the total moving, which
 * is the sort of drift that leaves a loan never quite closing.
 */
class InstallmentsRelationManager extends RelationManager
{
    use HasRelationManagerPermission;
    use RefreshesPayrollData;

    public static function viewPermission(): string
    {
        return ScreenPermissions::LOAN_INSTALLMENTS;
    }

    protected static string $relationship = 'installments';

    public static function getModelLabel(): string
    {
        return __('Instalment');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Instalments');
    }

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Instalments');
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('seq')
                    ->label(__('No.'))
                    ->sortable(),
                TextColumn::make('due_period')
                    ->label(__('Due Period'))
                    ->sortable(),
                TextColumn::make('amount')
                    ->label(Currency::label(__('Amount (AED)')))
                    ->numeric(decimalPlaces: 2)
                    ->summarize(Sum::make()->label(__('Total'))),
                TextColumn::make('payslip_id')
                    ->label(__('Status'))
                    ->badge()
                    ->state(fn (LoanInstallment $record): string => $record->payslip_id === null
                        ? __('Outstanding')
                        : __('Deducted'))
                    ->color(fn (LoanInstallment $record): string => $record->payslip_id === null
                        ? 'warning'
                        : 'success'),
            ])
            ->recordActions([
                // Nothing deducted for it this month: it and the later ones a month later, the loan ending a month later.
                Action::make('postpone')
                    ->label(__('Delay to next month'))
                    ->icon('heroicon-o-forward')
                    ->color('warning')
                    ->visible(fn (LoanInstallment $record): bool => ! $record->isDeducted()
                        && $this->getOwnerRecord()->getAttribute('status') === LoanStatus::ACTIVE
                        && (bool) auth()->user()?->can('Update:EmployeeLoan'))
                    ->requiresConfirmation()
                    ->modalHeading(fn (LoanInstallment $record): string => __('Delay instalment :no to next month', ['no' => $record->seq]))
                    ->modalDescription(__('Nothing is deducted for it in its month: it and every later instalment not yet deducted move one month later, with the same amounts, and the loan ends a month later.'))
                    ->action(function (LoanInstallment $record): void {
                        $moved = app(LoanScheduleService::class)->postpone($record);
                        Notification::make()->success()
                            ->title(trans_choice(':count instalment moved a month later.|:count instalments moved a month later.', $moved, ['count' => $moved]))
                            ->send();
                    }),
            ])
            ->defaultSort('seq')
            ->paginated(false)
            ->emptyStateHeading(__('No schedule built yet'));
    }
}
