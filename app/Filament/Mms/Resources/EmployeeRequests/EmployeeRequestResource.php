<?php

namespace App\Filament\Mms\Resources\EmployeeRequests;

use App\Enums\RequestStatus;
use App\Filament\Concerns\HasModuleGate;
use App\Filament\Mms\Resources\EmployeeRequests\Pages\CreateEmployeeRequest;
use App\Filament\Mms\Resources\EmployeeRequests\Pages\EditEmployeeRequest;
use App\Filament\Mms\Resources\EmployeeRequests\Pages\ListEmployeeRequests;
use App\Filament\Mms\Resources\EmployeeRequests\Pages\ViewEmployeeRequest;
use App\Filament\Mms\Resources\EmployeeRequests\Schemas\EmployeeRequestForm;
use App\Filament\Mms\Resources\EmployeeRequests\Schemas\EmployeeRequestInfolist;
use App\Filament\Mms\Resources\EmployeeRequests\Tables\EmployeeRequestsTable;
use App\Models\EmployeeRequest;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Human Resources → Employee requests: loans, petty cash and certificates — each employee their own, whoever
 * decides or pays them everyone's (as leave requests).
 */
class EmployeeRequestResource extends Resource
{
    use HasModuleGate;

    protected static ?string $model = EmployeeRequest::class;

    protected static string|null|BackedEnum $navigationIcon = Heroicon::OutlinedHandRaised;

    protected static ?int $navigationSort = 3;

    public static function moduleGateKey(): string
    {
        return 'mms_calendar';
    }

    public static function getModelLabel(): string
    {
        return __('Employee request');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Employee requests');
    }

    public static function getNavigationLabel(): string
    {
        return __('Employee requests');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Human Resources');
    }

    /** Each employee their own; everyone's only for a role given to see them all. None for one who is no employee. */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        if (auth()->user()?->can('manageOthers', EmployeeRequest::class)) {
            return $query;
        }

        $partyId = auth()->user()?->party?->getKey();

        return $partyId === null ? $query->whereRaw('1 = 0') : $query->where('party_id', $partyId);
    }

    /** What waits for whoever looks: requests to decide, and money to pay — one count. */
    public static function getNavigationBadge(): ?string
    {
        $user = auth()->user();
        $decides = (bool) $user?->can('approve', EmployeeRequest::class);
        $pays = (bool) $user?->can('pay', EmployeeRequest::class);

        // Of the requests they see: everyone's only for those who see them all.
        if ((! $decides && ! $pays) || ! $user?->can('viewAll', EmployeeRequest::class)) {
            return null;
        }

        $count = static::getModel()::query()
            ->where(fn (Builder $query) => $query
                ->when($decides, fn (Builder $q) => $q->orWhere('status', RequestStatus::PENDING->value))
                ->when($pays, fn (Builder $q) => $q->orWhere(fn (Builder $q) => $q->awaitingPayment())))
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function form(Schema $schema): Schema
    {
        return EmployeeRequestForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return EmployeeRequestInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return EmployeeRequestsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEmployeeRequests::route('/'),
            'create' => CreateEmployeeRequest::route('/create'),
            'view' => ViewEmployeeRequest::route('/{record}'),
            'edit' => EditEmployeeRequest::route('/{record}/edit'),
        ];
    }
}
