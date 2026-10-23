<?php

namespace App\Filament\Mms\Resources\MatterRequests;

use App\Enums\RequestStatus;
use App\Enums\RequestType;
use App\Filament\Mms\Resources\MatterRequests\Pages\ListMatterRequests;
use App\Filament\Mms\Resources\MatterRequests\Pages\ViewMatterRequest;
use App\Filament\Mms\Resources\MatterRequests\Schemas\MatterRequestInfolist;
use App\Filament\Mms\Resources\MatterRequests\Tables\MatterRequestsTable;
use App\Models\MatterRequest;
use App\Support\ContactSearch;
use App\Support\Dates;
use App\Support\RecordNumber;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class MatterRequestResource extends Resource
{
    protected static ?string $model = MatterRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::Newspaper;

    protected static ?int $navigationSort = 2;

    public static function getNavigationBadge(): ?string
    {
        return static::count();
    }

    public static function getPluralModelLabel(): string
    {
        return __('Requests');
    }

    public static function getModelLabel(): string
    {
        return __('Requests');
    }

    protected static bool $isGloballySearchable = true;

    protected static int $globalSearchResultsLimit = 10;

    /**
     * Searched as applyGlobalSearchAttributeConstraints() says; a field named so the panel searches it at all —
     * with none (no record title attribute), requests were never in the results.
     */
    public static function getGloballySearchableAttributes(): array
    {
        return ['comment'];
    }

    /**
     * Each word (a "/" splits too, so "123/2024" works) must match the
     * matter's year or number, the kind of request as it reads ("الصعوبة"),
     * who asked, or the comment.
     */
    protected static function applyGlobalSearchAttributeConstraints(Builder $query, string $search): void
    {
        // An email or a phone number: requests on the matters it is a main party's of, or asked by its owner.
        if ($contact = ContactSearch::parse($search)) {
            $query->where(fn (Builder $q) => $q
                ->whereHas('matter.mainPartiesOnly.party', fn (Builder $party) => ContactSearch::apply($party, $contact))
                ->orWhereHas('requestBy.party', fn (Builder $party) => ContactSearch::apply($party, $contact)));

            return;
        }

        $words = preg_split('/[\s\/]+/u', trim($search), -1, PREG_SPLIT_NO_EMPTY);

        foreach ($words as $word) {
            $types = collect(RequestType::cases())
                ->filter(fn (RequestType $type): bool => str_contains(mb_strtolower((string) $type->getLabel()), mb_strtolower($word)))
                ->map(fn (RequestType $type): string => $type->value)
                ->all();

            $number = RecordNumber::parse($word);

            $query->where(fn (Builder $q) => $q
                ->whereHas('matter', fn (Builder $m) => is_numeric($word)
                    ? $m->where('year', $word)->orWhere('number', $word)
                    : $m->where('number', 'like', "%{$word}%"))
                ->orWhereHas('requestBy', fn (Builder $u) => $u->where('name', 'like', "%{$word}%"))
                ->orWhere('comment', 'like', "%{$word}%")
                // Its own number: "12" or "#12".
                ->when($number !== null, fn (Builder $q) => $q->orWhere($q->qualifyColumn('id'), $number))
                ->when($types !== [], fn (Builder $q) => $q->orWhereIn('type', $types)));
        }
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return static::getEloquentQuery()->latest('id');
    }

    public static function getGlobalSearchResultTitle(Model $record): string
    {
        return '#'.$record->getKey().' '.trim($record->type?->getLabel().' — '.$record->matter?->year.'/'.$record->matter?->number, ' —/');
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return array_filter([
            __('Status') => $record->status?->getLabel(),
            __('Requester') => $record->requestBy?->name,
            __('Date') => $record->created_at?->format(Dates::dateFormat()),
        ]);
    }

    public static function getRecordTitle(?Model $record): ?string
    {
        // The $record is passed in automatically by Filament
        return $record?->type?->getLabel();
    }

    public static function infolist(Schema $schema): Schema
    {
        return MatterRequestInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MatterRequestsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    /**
     * Mirror MatterResource's row-level scoping. A request row surfaces its
     * matter's reference, the requester's comment and the reviewer's notes, so
     * a user who may only see their own matters must only see those matters'
     * requests. Without this the list exposed every matter in the office.
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['matter', 'requestBy', 'approvedBy']);

        $user = auth()->user();

        if (! $user->can('ViewAny:Matter') && $user->can('ViewOwn:Matter')) {
            $query->whereHas('matter', fn (Builder $q) => $q->ownedBy($user));
        }

        return $query;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMatterRequests::route('/'),
            // 'create' => CreateMatterRequest::route('/create'),
            'view' => ViewMatterRequest::route('/{record}'),
            // 'edit' => EditRequest::route('/{record}/edit'),
        ];
    }

    private static function count(): int
    {
        return static::getEloquentQuery()->where('status', RequestStatus::PENDING->value)->count();
    }
}
