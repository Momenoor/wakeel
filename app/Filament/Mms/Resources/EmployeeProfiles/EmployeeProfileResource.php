<?php

namespace App\Filament\Mms\Resources\EmployeeProfiles;

use App\Filament\Concerns\HasModuleGate;
use App\Filament\Mms\Resources\EmployeeProfiles\Pages\CreateEmployeeProfile;
use App\Filament\Mms\Resources\EmployeeProfiles\Pages\EditEmployeeProfile;
use App\Filament\Mms\Resources\EmployeeProfiles\Pages\ListEmployeeProfiles;
use App\Filament\Mms\Resources\EmployeeProfiles\RelationManagers\FlightTicketsRelationManager;
use App\Filament\Mms\Resources\EmployeeProfiles\RelationManagers\LeaveBalanceRelationManager;
use App\Filament\Mms\Resources\EmployeeProfiles\RelationManagers\SalaryComponentsRelationManager;
use App\Filament\Mms\Resources\EmployeeProfiles\Schemas\EmployeeProfileForm;
use App\Filament\Mms\Resources\EmployeeProfiles\Tables\EmployeeProfilesTable;
use App\Models\EmployeeProfile;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class EmployeeProfileResource extends Resource
{
    use HasModuleGate;

    protected static ?string $model = EmployeeProfile::class;

    protected static bool $isGloballySearchable = true;

    protected static int $globalSearchResultsLimit = 5;

    public static function getGloballySearchableAttributes(): array
    {
        return ['employee_no', 'display_name', 'party.name'];
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()->with('party');
    }

    public static function getGlobalSearchResultTitle(Model $record): string
    {
        return (string) ($record->display_name ?: $record->party?->name ?: $record->employee_no);
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return array_filter([__('Employee Number') => $record->employee_no]);
    }

    public static function moduleGateKey(): string
    {
        return 'mms_payroll';
    }

    public static function getModelLabel(): string
    {
        return __('Employee');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Employees');
    }

    public static function getNavigationLabel(): string
    {
        return __('Employees');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Human Resources');
    }

    protected static string|null|BackedEnum $navigationIcon = 'heroicon-o-identification';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'employee_no';

    public static function form(Schema $schema): Schema
    {
        return EmployeeProfileForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return EmployeeProfilesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            SalaryComponentsRelationManager::class,
            LeaveBalanceRelationManager::class,
            FlightTicketsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEmployeeProfiles::route('/'),
            'create' => CreateEmployeeProfile::route('/create'),
            'edit' => EditEmployeeProfile::route('/{record}/edit'),
        ];
    }
}
