<?php

namespace App\Filament\Mms\Resources\Matters\RelationManagers;

use App\Filament\Concerns\HasRelationManagerPermission;
use App\Filament\Concerns\RefreshesMatterPage;
use App\Filament\Forms\Components\RichText;
use App\Filament\Mms\Pages\UnfiledEmails;
use App\Filament\Mms\Resources\Matters\MatterResource;
use App\Filament\Support\CreditorFields;
use App\Filament\Support\EmailSendFields;
use App\Filament\Support\RegenerateEmailAction;
use App\Jobs\CheckMatterEmailsInOneDrive;
use App\Jobs\CollectMatterEmails;
use App\Jobs\EmailJob;
use App\Jobs\ReadMatterEmailsAddedInOneDrive;
use App\Models\Matter;
use App\Models\MatterContact;
use App\Models\MatterEmail;
use App\Models\Type;
use App\Models\User;
use App\Services\MMS\CreditorFirstMails;
use App\Services\MMS\EmailReplySender;
use App\Services\MMS\MatterCreditors;
use App\Services\MMS\MatterEmailDeletion;
use App\Services\MMS\MatterOneDriveFolders;
use App\Services\MMS\MatterReplyCollector;
use App\Services\MMS\ReceivedEmailFiling;
use App\Services\MMS\SenderMailer;
use App\Support\Addresses;
use App\Support\Dates;
use App\Support\Documents;
use App\Support\ScreenPermissions;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A matter's email log: what was sent from it (letters, minutes, bulk mail)
 * and what came in from its parties — put on it by Wakeel or chosen by hand.
 * Each can be opened here: who, when, how its matter was known, and — for an
 * email that came in — its PDF and the files it came with.
 */
class EmailsRelationManager extends RelationManager
{
    use HasRelationManagerPermission;
    use RefreshesMatterPage;

    protected static string $relationship = 'emails';

    public static function viewPermission(): string
    {
        return ScreenPermissions::MATTER_EMAILS_TAB;
    }

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Emails');
    }

    public static function getModelLabel(): string
    {
        return __('Email');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Emails');
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('subject')
            // Sent, and received ones that are on the matter — filed, or a reply (kept with no status) —
            // not those waiting for a matter, or set aside.
            ->modifyQueryUsing(fn (Builder $query) => self::listed($query)->with(['reads.user', 'user']))
            ->defaultSort('at', 'desc')
            // Columns of fixed widths, the text wrapped in them: the table as wide as the page, never scrolled sideways —
            // the actions column wide enough for its three buttons and menu (Filament gives its empty header 4px).
            ->extraAttributes(['class' => '[&_table]:table-fixed [&_table]:w-full [&_.fi-ta-content]:overflow-x-hidden [&_td]:align-top [&_.fi-ta-actions-header-cell]:w-52'])
            ->columns([
                // Each user's own: unread until they open it (or mark it read).
                IconColumn::make('unread')
                    ->label('')
                    ->state(fn (MatterEmail $record): bool => ! $record->isReadBy(auth()->user()))
                    ->icon(fn (bool $state): string => $state ? 'heroicon-s-envelope' : 'heroicon-o-envelope-open')
                    ->color(fn (bool $state): string => $state ? 'warning' : 'gray')
                    ->tooltip(fn (bool $state): string => $state ? __('Unread by you') : __('Read by you'))
                    ->width('4%'),
                // The date, the time under it.
                TextColumn::make('at')
                    ->label(__('Date'))
                    ->date()
                    ->description(fn (MatterEmail $record): ?string => $record->at ? Dates::time($record->at) : null)
                    ->sortable()
                    ->width('10%'),
                TextColumn::make('direction')
                    ->label(__('Direction'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => $state === MatterEmail::SENT ? __('Sent') : __('Received'))
                    ->color(fn (string $state): string => $state === MatterEmail::SENT ? 'info' : 'success')
                    ->width('8%'),
                TextColumn::make('party')
                    ->label(__('From / To'))
                    ->state(fn (MatterEmail $record): string => $record->direction === MatterEmail::SENT
                        ? implode('، ', (array) $record->to)
                        : (string) $record->from)
                    ->wrap()
                    ->extraAttributes(['style' => 'word-break: break-word;'])
                    ->width('20%'),
                TextColumn::make('subject')
                    ->label(__('Subject'))
                    ->weight('bold')
                    ->description(fn (MatterEmail $record): ?string => EmailJob::note($record, $this->getTableRecords()))

                    ->searchable()
                    ->wrap(),
                TextColumn::make('read_by')
                    ->label(__('Read by'))
                    ->state(fn (MatterEmail $record): array => self::readers($record))
                    ->listWithLineBreaks()
                    ->limitList(3)
                    ->expandableLimitedList()
                    ->placeholder(__('No one yet'))
                    ->wrap()
                    ->width('12%')
                    ->visibleFrom('lg')
                    ->toggleable(),
                // What of an email that came in is in the matter's OneDrive.
                TextColumn::make('pdf_saved')
                    ->label(__('PDF in OneDrive'))
                    ->badge()
                    ->state(fn (MatterEmail $record): ?string => $record->oneDriveLabel('pdf'))
                    ->color(fn (?string $state): string => self::color($state))
                    ->width('7%')
                    ->visibleFrom('xl'),
                TextColumn::make('eml_saved')
                    ->label(__('EML in OneDrive'))
                    ->tooltip(__('The original email, saved in the matter\'s OneDrive'))
                    ->badge()
                    ->state(fn (MatterEmail $record): ?string => $record->oneDriveLabel('eml'))
                    ->color(fn (?string $state): string => self::color($state))
                    ->width('7%')
                    ->visibleFrom('xl'),
                TextColumn::make('attachments_saved')
                    ->label(__('Attachments in OneDrive'))
                    ->badge()
                    ->state(fn (MatterEmail $record): ?string => $record->oneDriveLabel('attachments'))
                    // Documents as links to download — instead of attached, or beside them: to be seen, and
                    // fetched by hand.
                    ->color(fn (?string $state, MatterEmail $record): string => self::hasLinks($record) ? 'warning' : self::color($state))
                    ->icon(fn (MatterEmail $record): ?string => self::hasLinks($record) ? 'heroicon-o-link' : null)
                    ->tooltip(fn (MatterEmail $record): ?string => match (true) {
                        ! self::hasLinks($record) => null,
                        $record->attachmentsState() === 'none' => __('Its documents came as links to download, not attached — see Details.'),
                        default => __('Besides its attachments, it has links to documents to download, never saved by themselves — see Details.'),
                    })
                    ->width('7%')
                    ->visibleFrom('xl'),
                TextColumn::make('matched_by')
                    ->label(__('Matter known by'))
                    ->formatStateUsing(fn (?string $state): string => MatterEmail::matchedByLabel($state))
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('direction')
                    ->label(__('Direction'))
                    ->options([MatterEmail::SENT => __('Sent'), MatterEmail::RECEIVED => __('Received')]),
                Filter::make('unread_by_me')
                    ->label(__('Unread by me'))
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->unreadBy(auth()->user())),
                // A matter with creditors: the emails that came in from no creditor yet — to assign (Sender is a creditor).
                Filter::make('no_creditor')
                    ->label(__('Not assigned to a creditor'))
                    ->toggle()
                    ->visible(fn (): bool => MatterCreditors::kept($this->getOwnerRecord()))
                    ->query(fn (Builder $query): Builder => MatterCreditors::withoutCreditor($query)),
            ])
            ->recordActions([
                // The email's PDF, as kept — on the row, with Reply and Details.
                Action::make('open')
                    ->label(__('Open the email'))
                    ->icon('heroicon-o-document-text')
                    ->color('gray')
                    ->visible(fn (MatterEmail $record): bool => self::pdfUrl($record) !== null)
                    // Opened: read by whoever opened it.
                    ->extraAttributes(fn (MatterEmail $record): array => ['wire:click' => 'markEmailRead('.$record->getKey().')'])
                    ->url(fn (MatterEmail $record): ?string => self::pdfUrl($record), shouldOpenInNewTab: true),
                // An answer to an email that came in: to whoever wrote it (and their party's own address when they wrote
                // from another of theirs), copied to the others it reached, under the same thread — kept beside it.
                Action::make('reply')
                    ->label(__('Reply'))
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('success')
                    ->modalWidth('4xl')
                    ->modalSubmitActionLabel(__('Send'))
                    ->visible(fn (MatterEmail $record): bool => $record->direction === MatterEmail::RECEIVED && $record->matter_id !== null
                        && SenderMailer::options() !== [] && (auth()->user()?->can('update', $this->getOwnerRecord()) ?? false))
                    ->fillForm(fn (MatterEmail $record): array => [
                        // From the mailbox it came to, when the user may send from it.
                        'sender' => array_key_exists((string) $record->sender_key, SenderMailer::options()) ? $record->sender_key : EmailSendFields::defaultSender(),
                        'to' => EmailReplySender::to($record),
                        'cc' => EmailReplySender::cc($record),
                        'contacts_cc' => EmailSendFields::startingContactsCc($this->getOwnerRecord(), EmailReplySender::to($record), EmailReplySender::partyIds($record)),
                        'subject' => EmailReplySender::subject($record),
                        // The email replied to, quoted under what is written.
                        'body' => EmailReplySender::startingBody($record),
                    ])
                    ->schema(function (MatterEmail $record): array {
                        // Whom the reply goes to, for the folder of the files shared by link.
                        $to = fn (Get $get): array => Addresses::emails((array) ($get('to') ?? []));
                        $shareWhenOver = EmailSendFields::shareWhenOver($this->getOwnerRecord(), $record, EmailReplySender::subject($record), $to);

                        return [
                            EmailSendFields::sender(),
                            TagsInput::make('to')
                                ->label(__('To'))
                                ->helperText(__('Whoever wrote — and, when they wrote from another address of a party\'s, the party\'s own.'))
                                ->placeholder('name@example.com')
                                ->splitKeys(['Tab', ' ', ',', 'Enter'])
                                ->nestedRecursiveRules(['email'])
                                ->required(),
                            EmailSendFields::cc(),
                            // The other addresses they brought in, to copy in or not.
                            EmailSendFields::contactsCc($this->getOwnerRecord(), EmailReplySender::to($record), EmailReplySender::partyIds($record)),
                            TextInput::make('subject')
                                ->label(__('Subject'))
                                ->required()
                                ->maxLength(255),
                            RichText::make('body')
                                ->label(__('Email'))
                                ->email()
                                ->contentDirection(preg_match('/\p{Arabic}/u', (string) $record->subject) ? 'rtl' : 'ltr')
                                ->required()
                                // An email kept before its text was: the text read from the mailbox now, quoted under what is written.
                                ->hintAction(Action::make('quoteText')
                                    ->label(__('Quote its text'))
                                    ->icon('heroicon-o-chat-bubble-bottom-center-text')
                                    ->visible($record->body === null)
                                    ->action(function (Get $get, Set $set) use ($record): void {
                                        $text = app(MatterReplyCollector::class)->bodyOf($record);

                                        if ($text === null) {
                                            Notification::make()->warning()->title(__('Its text is no longer in the mailbox it came to.'))->send();

                                            return;
                                        }

                                        $set('body', EmailReplySender::withText((string) $get('body'), $record, $text));
                                    })),
                            EmailSendFields::attachments('reply-attachments', __('Sent with the reply, for this email only.'), $shareWhenOver),
                            EmailSendFields::oneDriveFiles($this->getOwnerRecord(), $shareWhenOver),
                            // The files shared by link now, to copy the link into the reply by hand.
                            ...EmailSendFields::sharedLink($this->getOwnerRecord(), $record, EmailReplySender::subject($record), $to),
                        ];
                    })
                    ->action(function (MatterEmail $record, array $data): void {
                        try {
                            $result = app(EmailReplySender::class)->send($record, $data, auth()->id());
                        } catch (\Throwable $e) {
                            Notification::make()->danger()->title(__('The reply could not be sent'))->body($e->getMessage())->send();

                            return;
                        }

                        $notification = Notification::make()
                            ->title($result['sent'] ? __('The reply is sent') : __('The reply could not be sent'))
                            ->body($result['errors'] !== [] ? implode("\n", $result['errors']) : null);
                        match (true) {
                            ! $result['sent'] => $notification->danger(),
                            $result['errors'] !== [] => $notification->warning(),
                            default => $notification->success(),
                        };
                        $notification->send();
                    }),
                Action::make('view')
                    ->label(__('Details'))
                    ->icon('heroicon-o-eye')
                    // Its details opened: read by whoever opened them.
                    ->mountUsing(fn (MatterEmail $record) => $record->markReadBy(auth()->user()))
                    ->modalHeading(fn (MatterEmail $record): string => (string) ($record->subject ?: __('(no subject)')))
                    ->schema(fn (MatterEmail $record): array => self::details($record))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel(__('Close')),
                // Open, Reply and Details on the row; everything else in its "…" menu.
                ActionGroup::make([
                    // Filed on the wrong matter: to another, with its files and its step in the progress.
                    Action::make('move')
                        ->label(__('Move to another matter'))
                        ->icon('heroicon-o-arrows-right-left')
                        ->color('warning')
                        ->visible(fn (MatterEmail $record): bool => $record->direction === MatterEmail::RECEIVED && (auth()->user()?->can('update', $this->getOwnerRecord()) ?? false))
                        ->modalWidth('lg')
                        ->modalDescription(__('Its PDF, original and attachments go to the other matter (in the system and its OneDrive), and its step in the progress with them.'))
                        ->schema([
                            Select::make('matter_id')
                                ->label(__('Matter'))
                                ->searchable()
                                ->getSearchResultsUsing(fn (string $search): array => array_diff_key(UnfiledEmails::searchMatters($search), [$this->getOwnerRecord()->getKey() => true]))
                                ->getOptionLabelUsing(fn ($value): ?string => UnfiledEmails::matterLabels([(int) $value])[(int) $value] ?? null)
                                ->helperText(__('Search by its number: 986, 986/2026 or 2026/986.'))
                                ->required(),
                        ])
                        ->action(function (MatterEmail $record, array $data): void {
                            $matter = Matter::findOrFail($data['matter_id']);
                            abort_unless(auth()->user()?->can('update', $matter), 403);

                            try {
                                $oneDrive = app(MatterReplyCollector::class)->move($record, $matter, auth()->id());
                            } catch (\Throwable $e) {
                                Notification::make()->danger()->title(__('The email could not be moved'))->body($e->getMessage())->send();

                                return;
                            }

                            Notification::make()
                                ->success()
                                ->title(__('Moved to matter :matter.', ['matter' => $matter->year.'/'.$matter->number]))
                                ->body($oneDrive['moved'] ? null : __('Nothing of it was found in this matter\'s OneDrive to move.'))
                                ->actions([Action::make('open')->label(__('View'))->url(MatterResource::getUrl('view', ['record' => $matter]))])
                                ->send();
                        }),
                    // A reply kept as a new email (it named no email the system knew): linked by hand to the one it answers.
                    Action::make('linkReply')
                        ->label(__('Is a reply to an email sent'))
                        ->icon('heroicon-o-link')
                        ->color('gray')
                        ->visible(fn (MatterEmail $record): bool => $record->direction === MatterEmail::RECEIVED
                            && $record->parent_id === null
                            && (auth()->user()?->can('update', $this->getOwnerRecord()) ?? false))
                        ->modalWidth('2xl')
                        ->modalDescription(__('Kept as a new email: choose the email sent from the system it answers, and it is shown as its reply.'))
                        ->schema(function (MatterEmail $record): array {
                            $sent = $record->mayAnswer();

                            return [
                                Select::make('sent_id')
                                    ->label(__('The email it answers'))
                                    ->options($sent->mapWithKeys(fn (MatterEmail $email): array => [$email->getKey() => implode(' — ', array_filter([
                                        $email->subject ?: __('(no subject)'),
                                        $email->at ? Dates::dateTime($email->at) : null,
                                        implode('، ', (array) $email->to),
                                    ]))])->all())
                                    // The one it most likely answers: from one it went to, of the same subject.
                                    ->default(MatterReplyCollector::answers(['from' => MatterEmail::addressOf((string) $record->from), 'subject' => (string) $record->subject, 'at' => $record->at?->toDateTimeString()], $sent)?->getKey())
                                    ->searchable()
                                    ->required(),
                            ];
                        })
                        ->action(function (MatterEmail $record, array $data): void {
                            $record->linkAsReplyTo(MatterEmail::query()->findOrFail($data['sent_id']));
                            Notification::make()->success()->title(__('Linked as a reply.'))->send();
                        }),
                    // A matter with creditors (a bankruptcy's): its sender one of them, or their agent — the emails from
                    // them moved to the creditor's folder, as those from them go from now on.
                    Action::make('creditor')
                        ->label(__('Sender is a creditor or their agent'))
                        ->icon('heroicon-o-banknotes')
                        ->color('gray')
                        ->visible(fn (MatterEmail $record): bool => $record->direction === MatterEmail::RECEIVED
                            && MatterCreditors::kept($this->getOwnerRecord())
                            && (auth()->user()?->can('update', $this->getOwnerRecord()) ?? false))
                        ->modalWidth('lg')
                        ->modalDescription(fn (MatterEmail $record): string => __('Its emails that came in on this matter, and those that come from :address from now on, are saved in the creditor\'s folder.', ['address' => MatterEmail::addressOf((string) $record->from)]))
                        ->schema(fn (MatterEmail $record): array => CreditorFields::schema($this->getOwnerRecord(), MatterContact::split((string) $record->from)[0] ?: MatterEmail::addressOf((string) $record->from), MatterEmail::addressOf((string) $record->from)))
                        ->action(function (MatterEmail $record, array $data): void {
                            try {
                                [$creditor, $moved] = CreditorFields::save($this->getOwnerRecord(), $data, [MatterEmail::addressOf((string) $record->from)]);
                            } catch (\Throwable $e) {
                                Notification::make()->danger()->title(__('The emails could not be moved'))->body($e->getMessage())->send();

                                return;
                            }

                            Notification::make()->success()
                                ->title(__('Saved as :creditor\'s', ['creditor' => $creditor->name]))
                                ->body(__(':count emails moved to the creditor\'s folder.', ['count' => $moved]))
                                ->send();
                        }),
                    Action::make('markRead')
                        ->label(__('Mark as read'))
                        ->icon('heroicon-o-envelope-open')
                        ->color('gray')
                        ->visible(fn (MatterEmail $record): bool => ! $record->isReadBy(auth()->user()))
                        ->action(fn (MatterEmail $record) => $record->markReadBy(auth()->user())),
                    // Read from OneDrive itself what is there.
                    Action::make('checkOneDrive')
                        ->label(__('Check OneDrive'))
                        ->icon('heroicon-o-cloud-arrow-down')
                        ->visible(fn (MatterEmail $record): bool => $record->direction === MatterEmail::RECEIVED)
                        ->action(fn (MatterEmail $record) => $this->checkOneDrive([$record])),
                    Action::make('markUnread')
                        ->label(__('Mark as unread'))
                        ->icon('heroicon-o-envelope')
                        ->visible(fn (MatterEmail $record): bool => $record->isReadBy(auth()->user()) && ! ($record->direction === MatterEmail::SENT && (int) $record->user_id === (int) auth()->id()))
                        ->action(fn (MatterEmail $record) => $record->markUnreadBy(auth()->user())),
                    // Its PDF made wrongly (garbled letters): made again from the mailbox, its files in their places.
                    RegenerateEmailAction::make(fn (): bool => auth()->user()?->can('update', $this->getOwnerRecord()) ?? false),
                    // A sent email marked as the first mail to a creditor-to-be: with the others, its day theirs.
                    Action::make('firstMail')
                        ->label(fn (MatterEmail $record): string => $record->first_mail ? __('Not a first mail to a creditor') : __('First mail to a creditor'))
                        ->icon(fn (MatterEmail $record): string => $record->first_mail ? 'heroicon-o-x-circle' : 'heroicon-o-flag')
                        ->visible(fn (MatterEmail $record): bool => $record->direction === MatterEmail::SENT
                            && MatterCreditors::kept($this->getOwnerRecord())
                            && (auth()->user()?->can('update', $this->getOwnerRecord()) ?? false))
                        ->requiresConfirmation()
                        ->modalDescription(fn (MatterEmail $record): string => $record->first_mail
                            ? __('It goes back to the other sent emails, and its day is no longer the first email sent to whom it went to.')
                            : __('It is saved with the other first mails to creditors (Settings → OneDrive Folders), named as set there, and its day is the first email sent to whom it went to (the Contacts tab).'))
                        ->action(function (MatterEmail $record): void {
                            try {
                                $record->first_mail ? app(CreditorFirstMails::class)->unmark($record) : app(CreditorFirstMails::class)->mark($record);
                            } catch (\Throwable $e) {
                                Notification::make()->danger()->title(__('The email could not be moved'))->body($e->getMessage())->send();

                                return;
                            }

                            Notification::make()->success()->title(__('Saved'))->send();
                        }),
                    // A super admin's: deleted for good, with all its files.
                    Action::make('delete')
                        ->label(__('Delete'))
                        ->icon('heroicon-o-trash')
                        ->color('danger')
                        ->visible(fn (): bool => MatterEmailDeletion::allowed())
                        ->requiresConfirmation()
                        ->modalHeading(fn (MatterEmail $record): string => __('Delete the email').': '.($record->subject ?: __('(no subject)')))
                        ->modalDescription(__('It is deleted for good with its PDF, original and attachments — in the system and in the matter\'s OneDrive (to its recycle bin), the copies in other folders too — and its step in the progress and the contacts learnt from it. Its replies stay.'))
                        ->action(function (MatterEmail $record): void {
                            abort_unless(MatterEmailDeletion::allowed(), 403);

                            try {
                                app(MatterEmailDeletion::class)->delete($record);
                            } catch (\Throwable $e) {
                                Notification::make()->danger()->title(__('The email could not be deleted'))->body($e->getMessage())->send();

                                return;
                            }

                            Notification::make()->success()->title(__('The email is deleted'))->send();
                        }),
                ]),
            ])
            ->headerActions([
                // The mailboxes read now, not at the next round (every ten minutes): replies, and any other
                // email from the matter's parties.
                Action::make('collectReplies')
                    ->label(__('Check for emails'))
                    ->tooltip(fn (): string => __("Replies, and any other email from the matter's parties").' — '.__('Last automatic check: :when', ['when' => MatterReplyCollector::lastRunSummary()]))
                    ->icon('heroicon-o-envelope-open')
                    ->color('gray')
                    ->visible(fn (): bool => MatterEmail::query()->where('direction', MatterEmail::SENT)->exists())
                    // On the queue: reading the mailboxes can take a minute, and the page must not wait on it.
                    ->action(fn () => CollectMatterEmails::start((int) $this->getOwnerRecord()->getKey())),
                Action::make('checkAllOneDrive')
                    ->label(__('Check OneDrive'))
                    ->tooltip(__('Reads from OneDrive which PDFs, originals and attachments of the emails that came in are there'))
                    ->icon('heroicon-o-cloud-arrow-down')
                    ->color('gray')
                    // On the queue: a folder of OneDrive asked for each day's emails took up to half a minute here.
                    ->action(function (): void {
                        CheckMatterEmailsInOneDrive::dispatch((int) $this->getOwnerRecord()->getKey(), auth()->id())->afterCommit();
                        Notification::make()->info()->title(__('Checking OneDrive'))->body(__('You are told in the bell when it is done.'))->send();
                    }),
                // The emails put in its OneDrive folder by hand, now — not waiting for the half-hourly reading. On
                // the queue: the folder and each file took seconds here.
                Action::make('readAddedEmails')
                    ->label(__('Read emails added in OneDrive'))
                    ->tooltip(__('Adds the emails put in the matter\'s OneDrive folder by hand (.eml, .msg) to its emails, progress and contacts'))
                    ->icon('heroicon-o-inbox-arrow-down')
                    ->color('gray')
                    ->visible(fn (): bool => auth()->user()?->can('update', $this->getOwnerRecord()) ?? false)
                    ->action(function (): void {
                        ReadMatterEmailsAddedInOneDrive::dispatch((int) $this->getOwnerRecord()->getKey(), auth()->id())->afterCommit();
                        Notification::make()->info()->title(__('Reading the emails added in OneDrive'))->body(__('You are told in the bell when it is done.'))->send();
                    }),
            ])
            ->emptyStateHeading(__('No emails yet'))
            ->emptyStateDescription(__('Emails sent from this matter, and those that come in from its parties, are listed here.'));
    }

    /**
     * The emails on the matter: sent, and received ones that are on it — filed, or a reply (kept with no status) —
     * not those waiting for a matter, or set aside.
     */
    private static function listed(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->where('direction', MatterEmail::SENT)
            ->orWhere('status', MatterEmail::FILED)
            ->orWhereNull('status'));
    }

    /** On the tab: how many of the matter's emails the user has not read yet. */
    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        $user = auth()->user();

        if (! $user instanceof User || ! method_exists($ownerRecord, 'emails')) {
            return null;
        }

        $unread = self::listed($ownerRecord->emails()->getQuery())->unreadBy($user)->count();

        return $unread > 0 ? (string) $unread : null;
    }

    public static function getBadgeColor(Model $ownerRecord, string $pageClass): ?string
    {
        return 'warning';
    }

    /**
     * Who read an email, in order — and, for one sent from here, who sent it.
     *
     * @return list<string>
     */
    private static function readers(MatterEmail $email): array
    {
        $names = $email->reads->sortBy('read_at')->map(fn ($read): ?string => $read->user?->name)->filter()->values()->all();

        if ($email->direction === MatterEmail::SENT && $email->user) {
            array_unshift($names, $email->user->name.' ('.__('sent it').')');
        }

        return array_values(array_unique($names));
    }

    /** An email opened from the list (its PDF): read by the user. */
    public function markEmailRead(int $emailId): void
    {
        $email = $this->getOwnerRecord()->emails()->whereKey($emailId)->first();
        $user = auth()->user();

        if ($email && $user instanceof User) {
            $email->markReadBy($user);
        }
    }

    /**
     * Reads from the matter's OneDrive what is there of these emails.
     *
     * @param  list<MatterEmail>  $emails
     */
    private function checkOneDrive(array $emails): void
    {
        try {
            $read = app(MatterOneDriveFolders::class)->checkEmails($this->getOwnerRecord(), $emails);
        } catch (\Throwable $e) {
            Notification::make()->danger()->title(__('OneDrive could not be read'))->body($e->getMessage())->send();

            return;
        }

        $read
            ? Notification::make()->success()->title(__('OneDrive checked'))->send()
            : Notification::make()->warning()->title(__('Nothing to check'))->body(__('This matter has no OneDrive folder, or no folder is set for received emails.'))->send();
    }

    private static function color(?string $state): string
    {
        return match ($state) {
            __('Saved') => 'success',
            __('Not saved') => 'danger',
            __('Partly saved') => 'warning',
            // An email that went out, with its files: "2 attachments".
            default => is_string($state) && preg_match('/\d/', $state) ? 'info' : 'gray',
        };
    }

    /** It came with links to documents to download — with no file, or beside its files. */
    private static function hasLinks(MatterEmail $email): bool
    {
        return $email->direction === MatterEmail::RECEIVED && $email->downloadLinks() !== [];
    }

    /** The URL of the email's PDF (the first file kept), when there is one. */
    private static function pdfUrl(MatterEmail $email): ?string
    {
        $path = $email->files[0]['path'] ?? null;

        return Documents::linkable($path) ? MatterEmail::fileUrl($path) : null;
    }

    /**
     * An email's details window: who, when, how its matter was known, what
     * of it is in OneDrive — then the files kept of it, each to open.
     *
     * @return list<Component>
     */
    private static function details(MatterEmail $email): array
    {
        $sent = $email->direction === MatterEmail::SENT;
        $folder = ((array) $email->onedrive)['folder'] ?? null;
        $attachmentsFolder = ((array) $email->onedrive)['attachments_folder'] ?? null;
        // Someone with more than one capacity on the matter: a copy in each other capacity's folders.
        $copies = collect((array) (((array) $email->onedrive)['copies'] ?? []))
            ->map(fn ($copy): string => (((array) $copy)['side'] ?? '') === ReceivedEmailFiling::CREDITOR ? __('Creditor') : Type::sideLabel($email->matter?->type, (string) (((array) $copy)['side'] ?? '')))
            ->zip(collect((array) (((array) $email->onedrive)['copies'] ?? []))->map(fn ($copy): string => (string) (((array) $copy)['folder'] ?? '')))
            ->map(fn ($pair): string => $pair[0].' — '.$pair[1])
            ->implode('؛ ');
        $files = $email->fileLinks();
        $links = $email->downloadLinks();

        $rows = array_filter([
            'at' => [__('Date'), $email->at?->format(Dates::dateTimeFormat())],
            'from' => [__('From'), $sent ? $email->user?->name : $email->from],
            'to' => [__('To'), implode('، ', (array) $email->to)],
            'cc' => [__('Cc'), implode('، ', (array) $email->cc)],
            'matched_by' => [__('Matter known by'), $sent ? null : MatterEmail::matchedByLabel($email->matched_by)],
            // What was kept of an email that came in.
            // Inside the matter's OneDrive folder — as it was when the email was saved.
            'onedrive_folder' => [__('OneDrive folder'), filled($folder) ? $folder : null],
            // Its files kept apart from it, when the side has an attachments folder.
            'onedrive_attachments_folder' => [__('Attachments folder'), filled($attachmentsFolder) ? $attachmentsFolder : null],
            'onedrive_copies' => [__('Also saved as'), $copies !== '' ? $copies : null],
            'onedrive_pdf' => [__('PDF in OneDrive'), $email->oneDriveLabel('pdf')],
            'onedrive_eml' => [__('EML in OneDrive'), $email->oneDriveLabel('eml')],
            'onedrive_attachments' => [__('Attachments in OneDrive'), $email->oneDriveLabel('attachments')],
        ], fn (array $row): bool => filled($row[1]));

        return [
            ...array_map(
                // Each value in its own direction: Arabic names beside Latin addresses read in order.
                fn (string $name, array $row): TextEntry => TextEntry::make($name)->label($row[0])->state($row[1])->inlineLabel()->extraAttributes(['dir' => 'auto']),
                array_keys($rows),
                $rows,
            ),
            TextEntry::make('kept_files')
                ->label(__('Files'))
                ->visible($files !== [])
                ->state(array_map(
                    // The first is the email itself, as a PDF.
                    fn (array $file, int $index): array => [...$file, 'name' => $index === 0 ? $file['name'].' ('.__('the email as PDF').')' : $file['name']],
                    $files,
                    array_keys($files),
                ))
                ->formatStateUsing(fn (array $state): string => $state['name'])
                ->url(fn (array $state): string => $state['url'], shouldOpenInNewTab: true)
                ->color('primary')
                ->listWithLineBreaks()
                ->bulleted()
                ->extraAttributes(['dir' => 'auto']),
            Text::make(__('No file was kept for this email.'))
                ->color('gray')
                ->visible($files === [] && ! $sent),
            self::downloadLinks($email)->visible($links !== []),
        ];
    }

    /** Documents it sent as links: not kept — each opened where it is, to download (here, and in Unfiled emails). */
    public static function downloadLinks(MatterEmail $email): TextEntry
    {
        return TextEntry::make('download_links')
            ->label(__('Download links'))
            ->helperText(__('Not kept in Wakeel: open each to download the document, then add it to the matter.'))
            ->state($email->downloadLinks())
            ->formatStateUsing(fn (array $state): string => $state['label'])
            ->url(fn (array $state): string => $state['url'], shouldOpenInNewTab: true)
            ->color('primary')
            ->icon('heroicon-o-arrow-top-right-on-square')
            ->listWithLineBreaks()
            ->bulleted()
            ->extraAttributes(['dir' => 'auto']);
    }
}
