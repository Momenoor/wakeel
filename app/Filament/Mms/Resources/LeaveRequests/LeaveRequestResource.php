<?php

namespace App\Filament\Mms\Resources\LeaveRequests;

use App\Enums\RequestStatus;
use App\Filament\Concerns\HasModuleGate;
use App\Filament\Mms\Resources\LeaveRequests\Pages\CreateLeaveRequest;
use App\Filament\Mms\Resources\LeaveRequests\Pages\EditLeaveRequest;
use App\Filament\Mms\Resources\LeaveRequests\Pages\ListLeaveRequests;
use App\Filament\Mms\Resources\LeaveRequests\Schemas\LeaveRequestForm;
use App\Filament\Mms\Resources\LeaveRequests\Tables\LeaveRequestsTable;
use App\Models\LeaveRequest;
use App\Support\Dates;
use App\Support\EmployeeSearch;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class LeaveRequestResource extends Resource
{
    use HasModuleGate;

    protected static ?string $model = LeaveRequest::class;

    public static function moduleGateKey(): string
    {
        return 'mms_calendar';
    }

    public static function getModelLabel(): string
    {
        return __('Leave Request');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Leave Requests');
    }

    public static function getNavigationLabel(): string
    {
        return __('Leave Requests');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Human Resources');
    }

    /** Under Employee requests: an employee's requests in one place. */
    public static function getNavigationParentItem(): ?string
    {
        return __('Employee requests');
    }

    protected static string|null|BackedEnum $navigationIcon = 'heroicon-o-inbox-arrow-down';

    protected static ?int $navigationSort = 2;

    /**
     * Employees see their own requests; management sees everyone's.
     *
     * The queue carries reasons for absence — illness, family matters — so an
     * unscoped list would hand every employee a readable history of their
     * colleagues' private circumstances. Scoped here rather than on the page so
     * the restriction holds for the badge, the table and any future widget
     * built on the resource.
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        if (auth()->user()?->can('manageOthers', LeaveRequest::class)) {
            return $query;
        }

        // A user with no linked employee record sees nothing rather than
        // everything: whereKey(null) would match no rows, but stating it as a
        // false condition makes the intent unmistakable.
        $partyId = auth()->user()?->party?->getKey();

        return $partyId === null
            ? $query->whereRaw('1 = 0')
            : $query->where('party_id', $partyId);
    }

    /**
     * How many decisions are waiting.
     *
     * Counted with a single aggregate rather than by hydrating the queue, since
     * this runs on every page load. Only shown to the people who can actually
     * decide — a badge counting requests an employee cannot act on is noise.
     */
    public static function getNavigationBadge(): ?string
    {
        if (! auth()->user()?->can('manageOthers', LeaveRequest::class)) {
            return null;
        }

        $pending = static::getModel()::query()
            ->where('status', RequestStatus::PENDING->value)
            ->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function form(Schema $schema): Schema
    {
        return LeaveRequestForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return LeaveRequestsTable::configure($table);
    }

    protected static bool $isGloballySearchable = true;

    protected static int $globalSearchResultsLimit = 5;

    /** Searched by the employee (EmployeeSearch); a field named so the panel searches it at all. */
    public static function getGloballySearchableAttributes(): array
    {
        return ['party.name'];
    }

    /** Found by the employee — their name, number, phone, email, Emirates ID or passport — or its own number ("#12"). */
    protected static function applyGlobalSearchAttributeConstraints(Builder $query, string $search): void
    {
        EmployeeSearch::requests($query, $search);
    }

    /** Only the requests one may see (getEloquentQuery()), the latest first. */
    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return static::getEloquentQuery()->with('party')->latest('start_date');
    }

    public static function getGlobalSearchResultTitle(Model $record): string
    {
        return '#'.$record->getKey().' '.trim(($record->requested_leave_type?->getLabel() ?? __('Leave')).' — '.$record->party?->name, ' —');
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return array_filter([
            __('Status') => $record->status?->getLabel(),
            __('Dates') => $record->start_date?->format(Dates::dateFormat()).' – '.$record->end_date?->format(Dates::dateFormat()),
            __('Days') => (string) $record->requestedDays(),
        ]);
    }

    /** No page of its own to view: edited by whoever may, else the list for whoever may see it; else not shown. */
    public static function getGlobalSearchResultUrl(Model $record): ?string
    {
        return match (true) {
            static::canEdit($record) => static::getUrl('edit', ['record' => $record]),
            static::canView($record) => static::getUrl('index'),
            default => null,
        };
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLeaveRequests::route('/'),
            'create' => CreateLeaveRequest::route('/create'),
            'edit' => EditLeaveRequest::route('/{record}/edit'),
        ];
    }
}
