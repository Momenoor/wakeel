<?php

namespace App\Filament\Shared\Resources\ErrorReports;

use App\Filament\Shared\Clusters\Settings;
use App\Filament\Shared\Resources\ErrorReports\Pages\ListErrorReports;
use App\Filament\Shared\Resources\ErrorReports\Pages\ViewErrorReport;
use App\Models\ErrorReport;
use App\Support\Dates;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Settings → Error tracking: the errors users ran into (App\Support\ErrorTracker), each under the
 * code the user was shown — what failed and where, who was signed in, what they were doing, and how
 * often the same error has happened. Read, marked resolved (every time it happened at once) and
 * deleted; nothing is added or changed by hand.
 */
class ErrorReportResource extends Resource
{
    protected static ?string $model = ErrorReport::class;

    protected static ?string $cluster = Settings::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBugAnt;

    protected static ?int $navigationSort = 65;

    protected static ?string $recordTitleAttribute = 'code';

    public static function getNavigationGroup(): ?string
    {
        return __('Maintenance');
    }

    public static function getNavigationLabel(): string
    {
        return __('Error tracking');
    }

    public static function getModelLabel(): string
    {
        return __('Error');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Error tracking');
    }

    /** How many are still to be looked at. */
    public static function getNavigationBadge(): ?string
    {
        // On every page of whoever has the screen: it must never be what breaks one — as it did while an
        // update had brought the code but not yet the table.
        try {
            $count = ErrorReport::query()->unresolved()->count();
        } catch (Throwable) {
            return null;
        }

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'danger';
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can(ErrorReport::PERMISSION) ?? false;
    }

    public static function canViewAny(): bool
    {
        return static::canAccess();
    }

    public static function canView(Model $record): bool
    {
        return static::canAccess();
    }

    public static function canDelete(Model $record): bool
    {
        return static::canAccess();
    }

    public static function canDeleteAny(): bool
    {
        return static::canAccess();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    /**
     * Each with how many times the same error happened — counted in the one query, not a query a row.
     *
     * @return Builder<ErrorReport>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['user', 'resolvedBy'])
            ->select('error_reports.*')
            ->selectSub(
                DB::table('error_reports as same')->selectRaw('count(*)')->whereColumn('same.fingerprint', 'error_reports.fingerprint'),
                'occurrences',
            );
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('code')
                    ->label(__('Error code'))
                    ->searchable()
                    ->copyable()
                    ->weight('bold')
                    ->fontFamily(FontFamily::Mono),
                TextColumn::make('created_at')
                    ->label(__('When'))
                    ->dateTime(Dates::dateTimeFormat())
                    ->description(fn (ErrorReport $record): ?string => $record->created_at?->diffForHumans())
                    ->sortable(),
                TextColumn::make('user.name')
                    ->label(__('User'))
                    ->placeholder(__('Nobody signed in'))
                    ->searchable(),
                TextColumn::make('message')
                    ->label(__('Error'))
                    ->description(fn (ErrorReport $record): string => class_basename($record->exception).' — '.$record->file.':'.$record->line)
                    ->limit(90)
                    ->wrap()
                    ->searchable(['message', 'exception', 'file']),
                TextColumn::make('source')
                    ->label(__('Source'))
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (string $state): string => self::sources()[$state] ?? $state),
                TextColumn::make('occurrences')
                    ->label(__('Times'))
                    ->badge()
                    ->color(fn (int $state): string => $state > 1 ? 'warning' : 'gray')
                    ->sortable(),
                TextColumn::make('resolved_at')
                    ->label(__('Status'))
                    ->badge()
                    ->state(fn (ErrorReport $record): string => $record->resolved_at ? __('Resolved') : __('Unresolved'))
                    ->color(fn (ErrorReport $record): string => $record->resolved_at ? 'success' : 'danger'),
                TextColumn::make('app_version')
                    ->label(__('Version'))
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('resolved_at')
                    ->label(__('Status'))
                    ->nullable()
                    ->placeholder(__('All'))
                    ->trueLabel(__('Resolved'))
                    ->falseLabel(__('Unresolved'))
                    ->default(false),
                SelectFilter::make('source')->label(__('Source'))->options(self::sources()),
                SelectFilter::make('user_id')->label(__('User'))->relationship('user', 'name')->searchable()->preload(),
                Filter::make('today')->label(__('Today'))->query(fn (Builder $query) => $query->where('created_at', '>=', today())),
            ])
            ->recordActions([
                ViewAction::make(),
                self::resolveAction(),
                self::reopenAction(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkAction::make('resolve')
                    ->label(__('Mark as resolved'))
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->deselectRecordsAfterCompletion()
                    ->action(fn (Collection $records) => $records->unique('fingerprint')->each->resolveAll()),
                DeleteBulkAction::make(),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        $mono = ['style' => 'font-family: ui-monospace, monospace; font-size: .8rem; white-space: pre-wrap; overflow-wrap: anywhere; direction: ltr; text-align: left;'];

        return $schema->components([
            Section::make(__('The error'))
                ->columns(3)
                ->columnSpanFull()
                ->schema([
                    TextEntry::make('code')->label(__('Error code'))->copyable()->weight('bold')->fontFamily(FontFamily::Mono),
                    TextEntry::make('created_at')->label(__('When'))->dateTime(Dates::dateTimeFormat()),
                    TextEntry::make('resolved_at')
                        ->label(__('Status'))
                        ->badge()
                        ->state(fn (ErrorReport $record): string => $record->resolved_at
                            ? __('Resolved').' — '.($record->resolvedBy?->name ?? '').' '.Dates::date($record->resolved_at)
                            : __('Unresolved'))
                        ->color(fn (ErrorReport $record): string => $record->resolved_at ? 'success' : 'danger'),
                    TextEntry::make('message')->label(__('Message'))->columnSpanFull()->extraAttributes($mono),
                    TextEntry::make('exception')->label(__('Kind'))->extraAttributes(['dir' => 'ltr']),
                    TextEntry::make('file')->label(__('Where'))->state(fn (ErrorReport $record): string => $record->file.':'.$record->line)->columnSpan(2)->extraAttributes(['dir' => 'ltr']),
                    TextEntry::make('occurrences')
                        ->label(__('Times this error happened'))
                        ->state(fn (ErrorReport $record): string => self::occurrences($record)),
                    TextEntry::make('status')->label(__('HTTP status'))->placeholder('—'),
                    TextEntry::make('app_version')->label(__('Version'))->placeholder('—'),
                ]),

            Section::make(__('Who and what'))
                ->columns(3)
                ->columnSpanFull()
                ->schema([
                    TextEntry::make('user.name')->label(__('User'))->placeholder(__('Nobody signed in')),
                    TextEntry::make('source')->label(__('Source'))->badge()->color('gray')->formatStateUsing(fn (string $state): string => self::sources()[$state] ?? $state),
                    TextEntry::make('ip')->label(__('IP address'))->placeholder('—'),
                    TextEntry::make('url')->label(__('Page'))->placeholder('—')->columnSpanFull()->extraAttributes(['dir' => 'ltr', 'style' => 'overflow-wrap: anywhere;']),
                    TextEntry::make('action')->label(__('What was being done'))->placeholder('—')->columnSpanFull()->extraAttributes(['dir' => 'ltr', 'style' => 'overflow-wrap: anywhere;']),
                    TextEntry::make('route')->label(__('Route'))->placeholder('—')->extraAttributes(['dir' => 'ltr']),
                    TextEntry::make('method')->label(__('Request method'))->placeholder('—'),
                    TextEntry::make('user_agent')->label(__('Browser'))->placeholder('—')->columnSpanFull()->extraAttributes(['dir' => 'ltr']),
                ]),

            Section::make(__('What was sent'))
                ->description(__('Passwords, tokens and keys are never kept.'))
                ->columnSpanFull()
                ->collapsed()
                ->visible(fn (ErrorReport $record): bool => filled($record->input))
                ->schema([
                    TextEntry::make('input')
                        ->hiddenLabel()
                        ->state(fn (ErrorReport $record): string => (string) json_encode($record->input, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
                        ->extraAttributes($mono),
                ]),

            Section::make(__('Full details'))
                ->description(__('For whoever maintains the system: the errors behind this one, and the path the code took to it.'))
                ->columnSpanFull()
                ->collapsed()
                ->schema([
                    TextEntry::make('previous')->label(__('Caused by'))->visible(fn (ErrorReport $record): bool => filled($record->previous))->extraAttributes($mono),
                    TextEntry::make('trace')->label(__('Trace'))->copyable()->extraAttributes($mono),
                ]),
        ]);
    }

    public static function resolveAction(): Action
    {
        return Action::make('resolve')
            ->label(__('Mark as resolved'))
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->visible(fn (ErrorReport $record): bool => $record->resolved_at === null)
            ->successNotificationTitle(__('Marked as resolved, with every other time it happened'))
            ->action(function (ErrorReport $record, Action $action): void {
                $record->resolveAll();
                $record->refresh();
                $action->success();
            });
    }

    public static function reopenAction(): Action
    {
        return Action::make('reopen')
            ->label(__('Reopen'))
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('gray')
            ->visible(fn (ErrorReport $record): bool => $record->resolved_at !== null)
            ->action(function (ErrorReport $record): void {
                $record->resolveAll(false);
                $record->refresh();
            });
    }

    /**
     * @return array<string, string>
     */
    private static function sources(): array
    {
        return [
            'web' => __('A page'),
            'livewire' => __('An action on a page'),
            'queue' => __('Background work'),
            'scheduler' => __('A scheduled task'),
            'console' => __('A command'),
        ];
    }

    /** "3 — first on 2 Nov 2026, last on 14 Nov 2026". */
    private static function occurrences(ErrorReport $record): string
    {
        $same = ErrorReport::query()->where('fingerprint', $record->fingerprint)
            ->selectRaw('count(*) as times, min(created_at) as first_at, max(created_at) as last_at')
            ->toBase()->first();

        if ((int) $same->times <= 1) {
            return __('Once');
        }

        return __(':times — first on :first, last on :last', [
            'times' => (int) $same->times,
            'first' => Dates::dateTime($same->first_at),
            'last' => Dates::dateTime($same->last_at),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListErrorReports::route('/'),
            'view' => ViewErrorReport::route('/{record}'),
        ];
    }
}
