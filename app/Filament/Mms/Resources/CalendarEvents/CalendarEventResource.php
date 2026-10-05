<?php

namespace App\Filament\Mms\Resources\CalendarEvents;

use App\Filament\Concerns\HasModuleGate;
use App\Filament\Mms\Resources\CalendarEvents\Pages\ListCalendarEvents;
use App\Filament\Mms\Resources\CalendarEvents\Schemas\CalendarEventForm;
use App\Filament\Mms\Resources\CalendarEvents\Tables\CalendarEventsTable;
use App\Models\CalendarEvent;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class CalendarEventResource extends Resource
{
    use HasModuleGate;

    protected static ?string $model = CalendarEvent::class;

    public static function moduleGateKey(): string
    {
        return 'mms_calendar';
    }

    protected static string|BackedEnum|null $navigationIcon = Heroicon::CalendarDays;

    protected static ?string $recordTitleAttribute = 'title';

    protected static bool $isGloballySearchable = true;

    protected static int $globalSearchResultsLimit = 10;

    /**
     * Title, place, or a linked matter ("123/2024", or year and number).
     */
    protected static function applyGlobalSearchAttributeConstraints(Builder $query, string $search): void
    {
        $words = preg_split('/[\s\/]+/u', trim($search), -1, PREG_SPLIT_NO_EMPTY);

        foreach ($words as $word) {
            $query->where(fn (Builder $q) => $q
                ->where('title', 'like', "%{$word}%")
                ->orWhere('location', 'like', "%{$word}%")
                ->orWhereHas('matters', fn (Builder $m) => $m->where('matters.number', $word)->orWhere('matters.year', $word)));
        }
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()->with('matters')->latest('start_datetime');
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return array_filter([
            __('Date') => $record->start_datetime?->translatedFormat($record->is_all_day ? 'j M Y' : 'j M Y, g:i A'),
            __('Matters') => $record->matters->map(fn ($matter) => $matter->year.'/'.$matter->number)->implode('، '),
        ]);
    }

    protected static ?int $navigationSort = 5;

    public static function getModelLabel(): string
    {
        return __('Calendar Event');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Calendar Events');
    }

    public static function form(Schema $schema): Schema
    {
        return CalendarEventForm::configure($schema);

    }

    public static function table(Table $table): Table
    {
        return CalendarEventsTable::configure($table);

    }

    public static function getPages(): array
    {
        return [
            'index' => ListCalendarEvents::route('/'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}
