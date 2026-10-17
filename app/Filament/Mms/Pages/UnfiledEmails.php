<?php

namespace App\Filament\Mms\Pages;

use App\Filament\Mms\Resources\Matters\MatterResource;
use App\Models\Matter;
use App\Models\MatterEmail;
use App\Services\MMS\Calendar\MatterReferenceMatcher;
use App\Services\MMS\MatterReplyCollector;
use App\Services\MMS\SenderMailer;
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
use Livewire\Attributes\Url;

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

    protected string $view = 'filament.mms.pages.unfiled-emails';

    /** The mailbox tab open: a sender key, or '' for all. */
    #[Url]
    public string $mailbox = '';

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
        return MatterReplyCollector::keepsAll()
            ? __('Emails whose matter is not certain, and every other email of the inbox. Choose the matter, or set aside one that is about none.')
            : __('Emails from parties on more than one matter, with nothing in them to say which. Choose the matter, or set aside one that is about none.');
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

    /**
     * "All", then every mailbox of the senders list — the one that received the email — with how
     * many wait in it (a mailbox since removed from the list, while its emails still wait, too).
     *
     * @return array<string, array{label: string, count: int}>
     */
    public function mailboxTabs(): array
    {
        $counts = self::waiting()->reorder()->selectRaw('sender_key, count(*) as total')->groupBy('sender_key')->pluck('total', 'sender_key');
        $tabs = ['' => ['label' => __('All'), 'count' => (int) $counts->sum()]];

        foreach (SenderMailer::all() as $key => $sender) {
            $tabs[(string) $key] = ['label' => (string) ($sender['name'] ?? '' ?: ($sender['address'] ?? $key)), 'count' => (int) ($counts[$key] ?? 0)];
        }

        foreach ($counts as $key => $total) {
            if (filled($key) && ! isset($tabs[(string) $key])) {
                $tabs[(string) $key] = ['label' => (string) $key, 'count' => (int) $total];
            }
        }

        return $tabs;
    }

    public function updatedMailbox(): void
    {
        $this->resetPage();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => self::waiting()->when($this->mailbox !== '', fn (Builder $q) => $q->where('sender_key', $this->mailbox)))
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
                    ->description(fn (MatterEmail $record): ?string => ($n = count($record->attachmentFiles()))
                        ? trans_choice(':count file|:count files', $n, ['count' => $n]) : null)
                    ->wrap(),
                TextColumn::make('candidates')
                    ->label(__('May be about'))
                    // The matters it may be about, one point each.
                    ->state(fn (MatterEmail $record): array => array_values(self::matterLabels((array) $record->candidates)))
                    ->listWithLineBreaks()
                    ->bulleted()
                    // A sender on no matter: any matter can be chosen by its number.
                    ->placeholder(__('No matter found')),
            ])

            ->recordActions([
                Action::make('open')
                    ->label(__('Open'))
                    ->icon('heroicon-o-document-text')
                    ->color('gray')
                    ->url(fn (MatterEmail $record): ?string => filled($path = $record->files[0]['path'] ?? null) ? MatterEmail::fileUrl($path) : null, shouldOpenInNewTab: true),

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

                Action::make('ignore')
                    ->label(__('Ignore sender'))
                    ->icon('heroicon-o-no-symbol')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalHeading(fn (MatterEmail $record): string => __('Never check emails from :address?', ['address' => MatterEmail::addressOf((string) $record->from)]))
                    ->modalDescription(__('Their emails waiting here are set aside, and new ones are not read (they stay unread in the mailbox). The address can be taken off the list in Settings → Integrations.'))
                    ->visible(fn (): bool => auth()->user()?->can('View:SystemSettings') ?? false)
                    ->action(function (MatterEmail $record): void {
                        $address = MatterEmail::addressOf((string) $record->from);
                        $count = app(MatterReplyCollector::class)->ignore($address);

                        Notification::make()
                            ->success()
                            ->title(__(':address will not be checked again.', ['address' => $address]))
                            ->body(trans_choice('{0} |{1} 1 waiting email set aside.|[2,*] :count waiting emails set aside.', $count, ['count' => $count]))
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
    public static function matterLabels(array $ids): array
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
    public static function searchMatters(string $search): array
    {
        $digits = array_values(array_filter(preg_split('/\D+/', $search) ?: [], fn ($d) => $d !== ''));
        if ($digits === []) {
            return [];
        }

        $ids = MatterResource::getEloquentQuery()
            ->withoutTrashed()
            ->when(count($digits) === 1, fn (Builder $q) => $q->where('number', 'like', $digits[0].'%'))
            // "986/2026" or "2026/986": the matter however its number is stored.
            ->when(count($digits) > 1, fn (Builder $q) => $q->whereIn('matters.id', [
                ...MatterReferenceMatcher::idsOf($digits[0], (int) $digits[1]),
                ...MatterReferenceMatcher::idsOf($digits[1], (int) $digits[0]),
            ]))
            ->reorder()
            ->latest('id')
            ->limit(30)
            ->pluck('matters.id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return self::matterLabels($ids);
    }
}
