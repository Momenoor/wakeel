<?php

namespace App\Filament\Mms\Pages;

use App\Filament\Mms\Resources\Matters\MatterResource;
use App\Models\Matter;
use App\Models\MatterEmail;
use App\Services\MMS\MatterReplyCollector;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;

/**
 * Emails from a matter's party whose matter Wakeel could not be sure of —
 * the sender on several matters, and nothing in the email to say which.
 * A person chooses (its PDF and files then go to that matter, a step in its
 * progress) or sets it aside. Each shows only to whoever can see one of the
 * matters it may be about.
 */
class UnfiledEmails extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.flight-tickets';

    protected static string|null|BackedEnum $navigationIcon = 'heroicon-o-inbox-stack';

    protected static ?int $navigationSort = 30;

    public static function getNavigationGroup(): ?string
    {
        return __('Communication');
    }

    public static function getNavigationLabel(): string
    {
        return __('Unfiled emails');
    }

    public function getTitle(): string
    {
        return __('Unfiled emails');
    }

    public function getSubheading(): ?string
    {
        return __('Emails from parties on more than one matter, with nothing in them to say which. Choose the matter, or set aside one that is about none.');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can('View:UnfiledEmails') ?? false;
    }

    public static function getNavigationBadge(): ?string
    {
        $count = once(fn () => self::waiting()->count());

        return $count ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    /**
     * Those waiting that the user may see: any, or — restricted to their own
     * matters — those that may be about one of them.
     *
     * @return Builder<MatterEmail>
     */
    public static function waiting(): Builder
    {
        $user = auth()->user();

        if (! $user || $user->can('ViewAny:Matter')) {
            return MatterEmail::pending();
        }

        $mine = $user->party
            ? Matter::query()->whereHas('matterParties', fn (Builder $q) => $q->where('party_id', $user->party->id))->pluck('id')->map(fn ($id) => (int) $id)->all()
            : [];

        return MatterEmail::pending($mine);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(self::waiting())
            ->modelLabel(__('Unfiled email'))
            ->pluralModelLabel(__('Unfiled emails'))
            ->defaultSort('at', 'desc')
            ->columns([
                TextColumn::make('at')
                    ->label(__('Received'))
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                TextColumn::make('from')
                    ->label(__('From'))
                    ->searchable()
                    ->wrap(),
                TextColumn::make('subject')
                    ->label(__('Subject'))
                    ->searchable()
                    ->weight('bold')
                    ->description(fn (MatterEmail $record): ?string => ($n = max(count((array) $record->files) - 1, 0))
                        ? trans_choice(':count file|:count files', $n, ['count' => $n]) : null)
                    ->wrap(),
                TextColumn::make('candidates')
                    ->label(__('May be about'))
                    ->state(fn (MatterEmail $record): array => array_values(self::matterLabels((array) $record->candidates)))
                    ->badge()
                    ->color('gray'),
            ])
            ->recordActions([
                Action::make('open')
                    ->label(__('Open'))
                    ->icon('heroicon-o-document-text')
                    ->color('gray')
                    ->url(fn (MatterEmail $record): ?string => filled($path = $record->files[0]['path'] ?? null) ? Storage::disk('public')->url($path) : null, shouldOpenInNewTab: true),

                Action::make('file')
                    ->label(__('File to matter'))
                    ->icon('heroicon-o-folder-arrow-down')
                    ->color('primary')
                    ->modalWidth('lg')
                    ->schema(fn (MatterEmail $record): array => [
                        Select::make('matter_id')
                            ->label(__('Matter'))
                            // The matters it may be about; any other by its number.
                            ->options(fn (): array => self::matterLabels((array) $record->candidates))
                            ->searchable()
                            ->getSearchResultsUsing(fn (string $search): array => self::searchMatters($search))
                            ->getOptionLabelUsing(fn ($value): ?string => self::matterLabels([(int) $value])[(int) $value] ?? null)
                            ->default(count((array) $record->candidates) === 1 ? (int) $record->candidates[0] : null)
                            ->required(),
                    ])
                    ->action(function (MatterEmail $record, array $data): void {
                        $matter = Matter::findOrFail($data['matter_id']);
                        abort_unless(auth()->user()?->can('update', $matter), 403);

                        app(MatterReplyCollector::class)->file($record, $matter, auth()->id());

                        Notification::make()
                            ->success()
                            ->title(__('Filed on matter :matter.', ['matter' => $matter->year.'/'.$matter->number]))
                            ->actions([Action::make('view')->label(__('View'))->url(MatterResource::getUrl('view', ['record' => $matter]))])
                            ->send();
                    }),

                Action::make('dismiss')
                    ->label(__('About no matter'))
                    ->icon('heroicon-o-archive-box-x-mark')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription(__('It is set aside and its kept PDF and files deleted; the email stays in the mailbox.'))
                    ->action(fn (MatterEmail $record) => app(MatterReplyCollector::class)->dismiss($record)),
            ])
            ->emptyStateIcon('heroicon-o-inbox')
            ->emptyStateHeading(__('No unfiled emails'))
            ->emptyStateDescription(__('Emails from the parties are kept on their matter by themselves; one appears here only when its matter is not certain.'));
    }

    /**
     * "2026/986 — plaintiff v defendant" for each matter.
     *
     * @param  list<int>  $ids
     * @return array<int, string>
     */
    private static function matterLabels(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return Matter::query()->whereKey($ids)->with('parties:id,name')->get(['id', 'year', 'number'])
            ->mapWithKeys(fn (Matter $m): array => [(int) $m->id => trim($m->year.'/'.$m->number.' — '.$m->parties->where('pivot.role', 'party')->pluck('name')->take(2)->implode(' / '), ' —')])
            ->all();
    }

    /**
     * Matters the user may see, by number ("986", "986/2026", "2026/986").
     *
     * @return array<int, string>
     */
    private static function searchMatters(string $search): array
    {
        $digits = array_values(array_filter(preg_split('/\D+/', $search) ?: [], fn ($d) => $d !== ''));
        if ($digits === []) {
            return [];
        }

        $ids = MatterResource::getEloquentQuery()
            ->withoutTrashed()
            ->when(count($digits) === 1, fn (Builder $q) => $q->where('number', 'like', $digits[0].'%'))
            ->when(count($digits) > 1, fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->where(fn (Builder $q) => $q->where('number', $digits[0])->where('year', $digits[1]))
                ->orWhere(fn (Builder $q) => $q->where('year', $digits[0])->where('number', $digits[1]))))
            ->reorder()
            ->latest('id')
            ->limit(30)
            ->pluck('matters.id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return self::matterLabels($ids);
    }
}
