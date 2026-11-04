<?php

namespace App\Filament\Mms\Resources\PayrollRuns;

use App\Filament\Concerns\KeptToFinanceViewers;
use App\Filament\Mms\Resources\PayrollRuns\Pages\CreatePayrollRun;
use App\Filament\Mms\Resources\PayrollRuns\Pages\ListPayrollRuns;
use App\Filament\Mms\Resources\PayrollRuns\Pages\ViewPayrollRun;
use App\Filament\Mms\Resources\PayrollRuns\RelationManagers\PayslipsRelationManager;
use App\Filament\Mms\Resources\PayrollRuns\Schemas\PayrollRunForm;
use App\Filament\Mms\Resources\PayrollRuns\Schemas\PayrollRunInfolist;
use App\Filament\Mms\Resources\PayrollRuns\Tables\PayrollRunsTable;
use App\Models\PayrollRun;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;

class PayrollRunResource extends Resource
{
    use KeptToFinanceViewers;

    protected static ?string $model = PayrollRun::class;

    public static function moduleGateKey(): string
    {
        return 'mms_payroll';
    }

    public static function getModelLabel(): string
    {
        return __('Payroll Run');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Payroll Runs');
    }

    public static function getNavigationLabel(): string
    {
        return __('Payroll Runs');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Financial');
    }

    protected static string|null|BackedEnum $navigationIcon = 'heroicon-o-calculator';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'period';

    /** Its period, and its company's name when it has one (two runs a month are told apart). */
    public static function getRecordTitle(?Model $record): string|Htmlable|null
    {
        return $record instanceof PayrollRun ? $record->label() : parent::getRecordTitle($record);
    }

    public static function form(Schema $schema): Schema
    {
        return PayrollRunForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return PayrollRunInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PayrollRunsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            PayslipsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPayrollRuns::route('/'),
            'create' => CreatePayrollRun::route('/create'),
            'view' => ViewPayrollRun::route('/{record}'),
        ];
    }
}
