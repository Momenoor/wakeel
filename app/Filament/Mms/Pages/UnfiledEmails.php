<?php

namespace App\Filament\Mms\Pages;

use App\Filament\Concerns\RendersFreshContent;
use App\Filament\Mms\Resources\Matters\MatterResource;
use App\Filament\Mms\Resources\Matters\RelationManagers\EmailsRelationManager;
use App\Filament\Support\RegenerateEmailAction;
use App\Jobs\CollectMatterEmails;
use App\Jobs\EmailJob;
use App\Jobs\FileUnfiledEmail;
use App\Models\Matter;
use App\Models\MatterEmail;
use App\Models\User;
use App\Services\MMS\Calendar\MatterReferenceMatcher;
use App\Services\MMS\MatterReplyCollector;
use App\Services\MMS\SenderMailer;
use App\Support\Dates;
use App\Support\MatterSearch;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Resources\Concerns\HasTabs;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Enums\TextSize;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
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
    use HasTabs;
    use InteractsWithTable;
    use RendersFreshContent;

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

    /** @return list<Action> */
    protected function getHeaderActions(): array
    {
        return [
            // Every mailbox read now, not waiting for the next round (every ten minutes) — on the queue.
            Action::make('checkMail')
                ->label(__('Check mail now'))
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->tooltip(fn (): string => __('Last automatic check: :when', ['when' => MatterReplyCollector::lastRunSummary()]))
                ->action(fn () => CollectMatterEmails::start()),
        ];
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
        // Someone's own mailbox: what waits there is theirs alone — any super admin's too.
        [$own, $others] = collect(SenderMailer::all())
            ->filter(fn (array $sender): bool => $sender['owner'] !== null)
            ->partition(fn (array $sender): bool => $sender['owner'] === $user?->getKey())
            ->map(fn ($mailboxes): array => array_map('strval', $mailboxes->keys()->all()))
            ->all();
        // And a shared mailbox they may not use (Users → Edit): nothing of it shown either.
        $others = [...$others, ...self::sharedHiddenFrom($user)];

        // All of them, or those that may be about their own matters (and their own mailbox's): as set for them (User::seesAllUnfiled).
        $query = ! $user || $user->seesAllUnfiled()
            ? MatterEmail::pending()
            : MatterEmail::pending(Matter::query()->ownedBy($user)->pluck('id')->map(fn ($id) => (int) $id)->all(), $own);

        return $query->when($others !== [], fn (Builder $q) => $q->where(fn (Builder $q) => $q->whereNull('sender_key')->orWhereNotIn('sender_key', $others)));
    }

    /**
     * The office's shared mailboxes left unticked for someone who may use some of them: their unfiled emails are
     * not theirs to see. One kept from them all still sees those that may be about their own matters.
     *
     * @return list<string>
     */
    private static function sharedHiddenFrom(?User $user): array
    {
        return ! $user?->shared_senders ? [] : collect(SenderMailer::all())
            ->filter(fn (array $sender, $key): bool => $sender['owner'] === null && ! $user->mayUseSharedMailbox((string) $key))
            ->keys()->map(fn ($key): string => (string) $key)->values()->all();
    }

    /**
     * Whether the files kept of an unfiled email (email-unfiled/{id}/…) open for the user: not when it came to a
     * colleague's own mailbox, or to a shared one they may not use.
     */
    public static function mayOpenFilesOf(int $emailId): bool
    {
        $key = MatterEmail::query()->whereKey($emailId)->value('sender_key');
        $owner = SenderMailer::ownerOf($key);

        return ($owner === null || $owner === auth()->id()) && ! in_array((string) $key, self::sharedHiddenFrom(auth()->user()), true);
    }

    /** Whether it waits in the user's own mailbox: theirs to file on any matter it may be about. */
    public static function inOwnMailbox(MatterEmail $email): bool
    {
        return SenderMailer::ownerOf($email->sender_key) === auth()->id();
    }

    /** The "All" tab's key — not a mailbox's (theirs are slugs: no underscores at the start). */
    public const ALL = '__all';

    /** The mailbox tab open — kept in the address, so a reload or a link keeps it. */
    #[Url(as: 'tab')]
    public ?string $activeTab = null;

    public function mount(): void
    {
        $this->loadDefaultActiveTab();
    }

    /** Each tab's count as it is after an action (filed, set aside), not before. */
    public function rendering(): void
    {
        unset($this->cachedTabs);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            $this->getTabsContentComponent(),
            EmbeddedTable::make(),
        ]);
    }

    /**
     * A tab for each mailbox — the one that received the email — with how many wait in it; none
     * when there is no mailbox at all.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        // No tabs for one kept from the shared mailboxes (their work account): their own emails only, as one list.
        if (! auth()->user()?->shared_senders) {
            return [];
        }

        $tabs = $this->mailboxTabs();

        if (count($tabs) <= 1) {
            return [];
        }

        return collect($tabs)->map(fn (array $tab, string $key): Tab => Tab::make($tab['label'])
            ->badge($tab['count'] ?: null)
            ->modifyQueryUsing(fn (Builder $query): Builder => $key === self::ALL ? $query : $query->where('sender_key', $key)))
            ->all();
    }

    /**
     * "All", then every mailbox of the senders list, with how many wait in it (a mailbox since
     * removed from the list, while its emails still wait, too).
     *
     * @return array<string, array{label: string, count: int}>
     */
    public function mailboxTabs(): array
    {
        $counts = self::waiting()->reorder()->selectRaw('sender_key, count(*) as total')->groupBy('sender_key')->pluck('total', 'sender_key');
        $tabs = [self::ALL => ['label' => __('All'), 'count' => (int) $counts->sum()]];

        // The shared mailboxes they may use and their own — never a colleague's.
        $hidden = self::sharedHiddenFrom(auth()->user());
        foreach (SenderMailer::all() as $key => $sender) {
            if ($sender['owner'] !== null && $sender['owner'] !== auth()->id() || in_array((string) $key, $hidden, true)) {
                continue;
            }

            $tabs[(string) $key] = ['label' => (string) ($sender['name'] ?? '' ?: ($sender['address'] ?? $key)), 'count' => (int) ($counts[$key] ?? 0)];
        }

        foreach ($counts as $key => $total) {
            if (filled($key) && ! isset($tabs[(string) $key])) {
                $tabs[(string) $key] = ['label' => (string) $key, 'count' => (int) $total];
            }
        }

        return $tabs;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(self::waiting())
            ->modifyQueryUsing($this->modifyQueryWithActiveTab(...))
            ->modelLabel(__('Unfiled email'))
            ->pluralModelLabel(__('Unfiled emails'))
            ->defaultSort('at', 'desc')
            ->columns([
                // The date, and the time under it: narrow, so the subject has the room.
                TextColumn::make('at')
                    ->label(__('Received'))
                    ->date()
                    ->description(fn (MatterEmail $record): ?string => $record->at?->format(Dates::timeFormat()))
                    ->sortable(),
                TextColumn::make('from')
                    ->label(__('From'))
                    ->searchable()
                    ->wrap(),
                TextColumn::make('subject')
                    ->label(__('Subject'))
                    ->searchable()
                    ->weight('bold')
                    ->description(fn (MatterEmail $record): ?string => EmailJob::note($record, $this->getTableRecords()))

                    ->wrap(),
                TextColumn::make('candidates')
                    ->label(__('May be about'))
                    // The matters it may be about, one point each.
                    ->state(fn (MatterEmail $record): array => array_values(array_intersect_key($this->candidateLabels(), array_flip(array_map('intval', (array) $record->candidates)))))
                    ->listWithLineBreaks()
                    ->bulleted()
                    // Long names (parties, court, kind): small, and wrapped rather than widening the table.
                    ->size(TextSize::ExtraSmall)
                    ->wrap()
                    // A sender on no matter: any matter can be chosen by its number.
                    ->placeholder(__('No matter found')),
            ])

            ->recordActions([
                Action::make('open')
                    ->label(__('Open'))
                    ->icon('heroicon-o-document-text')
                    ->color('gray')
                    ->url(fn (MatterEmail $record): ?string => filled($path = $record->files[0]['path'] ?? null) ? MatterEmail::fileUrl($path) : null, shouldOpenInNewTab: true),

                // The files it came with, each to open — to tell what it is about before filing it.
                Action::make('attachments')
                    ->label(fn (MatterEmail $record): string => __('Attachments').' ('.count($record->attachmentFiles()).')')
                    ->icon('heroicon-o-paper-clip')
                    ->color('gray')
                    ->visible(fn (MatterEmail $record): bool => $record->attachmentFiles() !== [])
                    ->modalHeading(fn (MatterEmail $record): string => __('Attachments').': '.$record->subject)
                    ->modalWidth('lg')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel(__('Close'))
                    ->schema(fn (MatterEmail $record): array => [
                        TextEntry::make('attachments')
                            ->hiddenLabel()
                            ->state($record->fileLinks(attachmentsOnly: true))
                            ->formatStateUsing(fn (array $state): string => $state['name'])
                            ->url(fn (array $state): string => $state['url'], shouldOpenInNewTab: true)
                            ->color('primary')
                            ->icon('heroicon-o-paper-clip')
                            ->listWithLineBreaks()
                            ->extraAttributes(['dir' => 'auto']),
                    ]),

                // Its documents sent as links to download — to tell what it is about too.
                Action::make('downloadLinks')
                    ->label(fn (MatterEmail $record): string => __('Download links').' ('.count($record->downloadLinks()).')')
                    ->icon('heroicon-o-link')
                    ->color('warning')
                    ->visible(fn (MatterEmail $record): bool => $record->downloadLinks() !== [])
                    ->modalHeading(fn (MatterEmail $record): string => __('Download links').': '.$record->subject)
                    ->modalWidth('lg')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel(__('Close'))
                    ->schema(fn (MatterEmail $record): array => [EmailsRelationManager::downloadLinks($record)->hiddenLabel()]),

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
                        abort_unless(self::mayFile($record, $matter), 403);

                        // In the background (its files go to the matter and its OneDrive): told in the bell when done.
                        match ((new FileUnfiledEmail((int) $record->getKey(), (int) $matter->getKey(), auth()->id()))->start()) {
                            EmailJob::BUSY => Notification::make()->warning()->title(__('Something is already being done to this email. Try again in a minute.'))->send(),
                            EmailJob::QUEUED => Notification::make()->success()->title(__('Being filed on matter :matter: you are told in the bell when it is done.', ['matter' => $matter->year.'/'.$matter->number]))->send(),
                            default => null,
                        };
                    })
                    ->databaseTransaction(false)
                    ->hidden(fn (MatterEmail $record): bool => EmailJob::busy((int) $record->getKey(), $this->getTableRecords()) !== null),

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

                // Its PDF made wrongly: made again from the mailbox, its files in their places — last, in the row's "…" menu.
                ActionGroup::make([RegenerateEmailAction::make(fn (): bool => true)]),
            ])
            // The same, to the emails ticked.
            ->toolbarActions([
                BulkActionGroup::make([
                    self::fileAll(),
                    self::ignoreAll(),
                    self::dismissAll(),
                    RegenerateEmailAction::bulk(fn (): bool => true),
                ]),
            ])
            ->emptyStateIcon('heroicon-o-inbox')
            ->emptyStateHeading(__('No unfiled emails'))
            ->emptyStateDescription(__('Emails from the parties are kept on their matter by themselves; one appears here only when its matter is not certain.'));
    }

    /** Who may file an email on a matter: one who may change the matter — or the owner of the mailbox it came to, on a matter it may be about. */
    public static function mayFile(MatterEmail $email, Matter $matter): bool
    {
        return (bool) auth()->user()?->can('update', $matter)
            || (self::inOwnMailbox($email) && in_array((int) $matter->getKey(), array_map('intval', (array) $email->candidates), true));
    }

    /** The emails ticked filed on one matter, each in the background (FileUnfiledEmail); those that may not be, left. */
    private static function fileAll(): BulkAction
    {
        return BulkAction::make('fileAll')
            ->label(__('File to matter'))
            ->icon('heroicon-o-folder-arrow-down')
            ->color('primary')
            ->modalWidth('lg')
            ->schema(fn (Collection $records): array => [
                Select::make('matter_id')
                    ->label(__('Matter'))
                    // The matters they may be about; any other by its number.
                    ->options(fn (): array => self::matterLabels($records->flatMap(fn (MatterEmail $email): array => array_map('intval', (array) $email->candidates))->unique()->values()->all()))
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search): array => self::searchMatters($search))
                    ->getOptionLabelUsing(fn ($value): ?string => self::matterLabels([(int) $value])[(int) $value] ?? null)
                    ->required(),
            ])
            ->databaseTransaction(false)
            ->deselectRecordsAfterCompletion()
            ->action(function (Collection $records, array $data): void {
                $matter = Matter::findOrFail($data['matter_id']);
                $results = $records->map(fn (MatterEmail $email): string => self::mayFile($email, $matter)
                    ? (new FileUnfiledEmail((int) $email->getKey(), (int) $matter->getKey(), auth()->id()))->start()
                    : 'refused')->countBy();

                Notification::make()
                    ->success()
                    ->title(__('Filed on matter :matter: :count emails.', ['matter' => $matter->year.'/'.$matter->number, 'count' => ($results[EmailJob::QUEUED] ?? 0) + ($results[EmailJob::DONE] ?? 0)]))
                    ->body(collect([
                        ($results[EmailJob::QUEUED] ?? 0) ? __('Being filed in the background: you are told in the bell when each is done.') : null,
                        ($results[EmailJob::BUSY] ?? 0) ? __('Already being worked on, left: :count.', ['count' => $results[EmailJob::BUSY]]) : null,
                        ($results['refused'] ?? 0) ? __('Not yours to file on that matter, left: :count.', ['count' => $results['refused']]) : null,
                    ])->filter()->implode(' '))
                    ->send();
            });
    }

    /** The senders of the emails ticked never checked again (ignore), their waiting emails set aside. */
    private static function ignoreAll(): BulkAction
    {
        return BulkAction::make('ignoreAll')
            ->label(__('Ignore senders'))
            ->icon('heroicon-o-no-symbol')
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading(fn (Collection $records): string => __('Never check emails from these :count senders?', ['count' => $records->map(fn (MatterEmail $email): string => MatterEmail::addressOf((string) $email->from))->unique()->count()]))
            ->modalDescription(__('Their emails waiting here are set aside, and new ones are not read (they stay unread in the mailbox). The address can be taken off the list in Settings → Integrations.'))
            ->visible(fn (): bool => auth()->user()?->can('View:SystemSettings') ?? false)
            ->deselectRecordsAfterCompletion()
            ->action(function (Collection $records): void {
                $collector = app(MatterReplyCollector::class);
                $addresses = $records->map(fn (MatterEmail $email): string => MatterEmail::addressOf((string) $email->from))->filter()->unique();
                $count = $addresses->sum(fn (string $address): int => $collector->ignore($address));

                Notification::make()
                    ->success()
                    ->title(__(':count senders will not be checked again.', ['count' => $addresses->count()]))
                    ->body(trans_choice('{0} |{1} 1 waiting email set aside.|[2,*] :count waiting emails set aside.', $count, ['count' => $count]))
                    ->send();
            });
    }

    /** The emails ticked set aside as about no matter, their kept files deleted. */
    private static function dismissAll(): BulkAction
    {
        return BulkAction::make('dismissAll')
            ->label(__('About no matter'))
            ->icon('heroicon-o-archive-box-x-mark')
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription(__('They are set aside and their kept PDFs and files deleted; the emails stay in the mailbox.'))
            ->deselectRecordsAfterCompletion()
            ->action(function (Collection $records): void {
                $collector = app(MatterReplyCollector::class);
                $records->each(fn (MatterEmail $email) => $collector->dismiss($email));

                Notification::make()->success()->title(__(':count emails set aside.', ['count' => $records->count()]))->send();
            });
    }

    /**
     * The labels of every matter the emails on the page may be about, read once for the page.
     *
     * @return array<int, string>
     */
    public function candidateLabels(): array
    {
        return once(fn (): array => self::matterLabels(
            $this->getTableRecords()->flatMap(fn (MatterEmail $email): array => array_map('intval', (array) $email->candidates))->unique()->values()->all(),
        ));
    }

    /**
     * Each matter, short (MatterSearch::label): its number, its parties cut short, its degree, nature, phase
     * and whether it is finished — two of one number told apart, in a table with much else in it.
     *
     * @param  list<int>  $ids
     * @return array<int, string>
     */
    public static function matterLabels(array $ids): array
    {
        return $ids === [] ? [] : MatterSearch::labels($ids, short: true);
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
