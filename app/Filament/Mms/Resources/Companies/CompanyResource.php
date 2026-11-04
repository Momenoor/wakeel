<?php

namespace App\Filament\Mms\Resources\Companies;

use App\Filament\Concerns\HasModuleGate;
use App\Filament\Mms\RelationManagers\DocumentsRelationManager;
use App\Filament\Mms\Resources\Companies\Pages\CreateCompany;
use App\Filament\Mms\Resources\Companies\Pages\EditCompany;
use App\Filament\Mms\Resources\Companies\Pages\ListCompanies;
use App\Filament\Mms\Resources\Companies\Schemas\CompanyForm;
use App\Filament\Mms\Resources\Companies\Tables\CompaniesTable;
use App\Filament\Support\ExpiringDocuments;
use App\Models\Company;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

/**
 * Human Resources → Companies: the office's companies, each with its trade licence (number, issuer, start and
 * expiry) and establishment card, the copies of both kept as its documents, and an alarm — in the table, on the
 * menu and on the bell — as either nears its expiry.
 */
class CompanyResource extends Resource
{
    use HasModuleGate;

    protected static ?string $model = Company::class;

    protected static string|null|BackedEnum $navigationIcon = 'heroicon-o-building-office-2';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'name';

    public static function moduleGateKey(): string
    {
        return 'mms_payroll';
    }

    public static function getModelLabel(): string
    {
        return __('Company');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Companies');
    }

    public static function getNavigationLabel(): string
    {
        return __('Companies');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Human Resources');
    }

    /** How many companies have a document expired or expiring soon. */
    public static function getNavigationBadge(): ?string
    {
        return ExpiringDocuments::badge(Company::query(), Company::EXPIRING);
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'danger';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return ExpiringDocuments::badgeTooltip();
    }

    public static function form(Schema $schema): Schema
    {
        return CompanyForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CompaniesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [DocumentsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCompanies::route('/'),
            'create' => CreateCompany::route('/create'),
            'edit' => EditCompany::route('/{record}/edit'),
        ];
    }
}
