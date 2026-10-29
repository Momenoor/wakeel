<?php

namespace App\Filament\Mms\Pages;

use App\Filament\Concerns\HasModuleGate;
use App\Filament\Shared\Clusters\Settings;
use App\Models\MailboxFolderForward;
use App\Models\MailSender;
use App\Services\MMS\MailboxFolderForwarder;
use App\Support\Dates;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Livewire\Attributes\Url;
use Throwable;

/**
 * A mailbox's folders (Settings → Mailbox folders): any of them chosen, its latest emails shown, each forwarded on
 * by hand — and the folder itself forwarded to an address of its own, its every new email sent on there
 * (MailboxFolderForwarder).
 */
class MailboxFolderEmails extends Page implements HasTable
{
    use HasModuleGate;
    use InteractsWithTable;

    /** So many of the folder's newest emails shown. */
    public const SHOWN = 100;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFolderOpen;

    protected static ?string $cluster = Settings::class;

    protected static ?string $slug = 'mailbox-folders/emails';

    protected static bool $shouldRegisterNavigation = false;

    #[Url]
    public string $mailbox = '';

    #[Url]
    public string $folder = 'INBOX';

    /** Why the folder could not be read, when it couldn't. */
    public ?string $readError = null;

    /** @var list<array<string, mixed>>|null The folder's emails, read once a request. */
    private ?array $emails = null;

    public static function moduleGateKey(): string
    {
        return MailboxFolderForwarder::MODULE;
    }

    public static function canAccess(): bool
    {
        return static::isModuleEnabled() && (auth()->user()?->isSuperAdmin() ?? false);
    }

    public function mount(): void
    {
        abort_unless($this->mailSender() !== null, 404);
    }

    public function getTitle(): string
    {
        return __('Folders of :mailbox', ['mailbox' => (string) $this->mailSender()?->address]);
    }

    public function getSubheading(): ?string
    {
        $forward = $this->forward();

        return match (true) {
            $forward === null => __('This folder is not forwarded.'),
            ! $forward->is_active => __('Forwarding to :to is paused.', ['to' => $forward->forward_to]),
            filled($forward->error) => __('Forwarded to :to — the last run failed: :error', ['to' => $forward->forward_to, 'error' => $forward->error]),
            default => __('Every new email of this folder is forwarded to :to.', ['to' => $forward->forward_to]),
        };
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('folder')
                ->label(__('Folder'))
                ->options(fn (): array => $this->folders())
                ->selectablePlaceholder(false)
                ->searchable()
                ->live()
                ->afterStateUpdated(function (): void {
                    $this->emails = null;
                    $this->resetTable();
                }),
            EmbeddedTable::make(),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('back')
                ->label(__('Mailbox folders'))
                ->icon(Heroicon::OutlinedArrowLeft)
                ->color('gray')
                ->url(MailboxFolders::getUrl()),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): array => $this->emails())
            ->paginated(false)
            ->columns([
                TextColumn::make('from')
                    ->label(__('From'))
                    ->state(fn (array $record): string => $record['from_name'] !== '' ? $record['from_name'] : $record['from'])
                    ->description(fn (array $record): ?string => $record['from_name'] !== '' ? $record['from'] : null)
                    ->weight(fn (array $record): ?string => $record['seen'] ? null : 'bold'),
                TextColumn::make('subject')->label(__('Subject'))->wrap()->placeholder('—'),
                TextColumn::make('at')->label(__('Date'))->state(fn (array $record): string => Dates::dateTime($record['at']))->placeholder('—'),
                TextColumn::make('forwarded')->label(__('Forwarded'))->wrap()->placeholder('—')
                    ->color(fn (array $record): string => $record['forward_failed'] ? 'danger' : 'success'),
            ])
            ->headerActions([
                Action::make('forwarding')
                    ->label(fn (): string => $this->forward() ? __('Forwarding') : __('Forward this folder'))
                    ->icon(Heroicon::OutlinedArrowUturnRight)
                    ->modalHeading(fn (): string => __('Forward the folder :folder', ['folder' => $this->folderName()]))
                    ->modalDescription(__('Every email that comes into this folder is forwarded from this mailbox to the address, its original attached as it came — a reply to it goes to its own sender.'))
                    ->fillForm(fn (): array => ['forward_to' => $this->forward()?->forward_to, 'is_active' => $this->forward()?->is_active ?? true, 'existing' => false])
                    ->schema(fn (): array => [
                        TextInput::make('forward_to')->label(__('Forward to'))->email()->required()->maxLength(255),
                        Toggle::make('is_active')->label(__('Active'))->visible($this->forward() !== null),
                        Toggle::make('existing')
                            ->label(__('Forward the emails already in it too'))
                            ->helperText(__('Off: only those that come from now on.'))
                            ->visible($this->forward() === null),
                    ])
                    ->action(fn (array $data) => $this->saveForward($data)),
                Action::make('forwardNow')
                    ->label(__('Forward new emails now'))
                    ->icon(Heroicon::OutlinedPaperAirplane)
                    ->color('gray')
                    ->visible(fn (): bool => (bool) $this->forward()?->is_active)
                    ->action(function (): void {
                        $forward = $this->forward();
                        $sent = app(MailboxFolderForwarder::class)->forwardNew($forward);
                        $forward->refresh();

                        filled($forward->error)
                            ? Notification::make()->danger()->title(__(':count emails forwarded.', ['count' => $sent]))->body($forward->error)->send()
                            : Notification::make()->success()->title(__(':count emails forwarded.', ['count' => $sent]))->send();
                        $this->emails = null;
                    }),
                Action::make('stopForwarding')
                    ->label(__('Stop forwarding'))
                    ->icon(Heroicon::OutlinedXMark)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (): bool => $this->forward() !== null)
                    ->action(function (): void {
                        $this->forward()?->delete();
                        Notification::make()->success()->title(__('The folder is no longer forwarded.'))->send();
                    }),
            ])
            ->recordActions([
                Action::make('forward')
                    ->label(__('Forward'))
                    ->icon(Heroicon::OutlinedArrowUturnRight)
                    ->color('gray')
                    ->fillForm(fn (): array => ['to' => $this->forward()?->forward_to])
                    ->schema([TextInput::make('to')->label(__('Forward to'))->email()->required()])
                    ->action(function (array $record, array $data): void {
                        try {
                            app(MailboxFolderForwarder::class)->forwardOne($this->mailSender(), $this->folder, (int) $record['uid'], (string) $data['to']);
                            Notification::make()->success()->title(__('Forwarded to :to', ['to' => $data['to']]))->send();
                        } catch (Throwable $e) {
                            Notification::make()->danger()->title(__('The email could not be forwarded'))->body($e->getMessage())->persistent()->send();
                        }

                        $this->emails = null;
                    }),
            ])
            ->emptyStateHeading(fn (): string => $this->readError !== null ? __('The folder could not be read') : __('No emails in this folder'))
            ->emptyStateDescription(fn (): ?string => $this->readError);
    }

    /**
     * The folder forwarded on — set, changed, or paused; first set, from its newest email now unless those already
     * in it are to go too.
     *
     * @param  array<string, mixed>  $data
     */
    private function saveForward(array $data): void
    {
        $mailbox = $this->mailSender();
        $forward = $this->forward();

        try {
            if ($forward === null) {
                MailboxFolderForward::query()->create([
                    'mail_sender_id' => $mailbox->getKey(),
                    'folder' => $this->folder,
                    'forward_to' => $data['forward_to'],
                    'last_uid' => ($data['existing'] ?? false) ? 0 : app(MailboxFolderForwarder::class)->lastUid($mailbox, $this->folder),
                ]);
            } else {
                $forward->update(['forward_to' => $data['forward_to'], 'is_active' => (bool) ($data['is_active'] ?? true), 'error' => null]);
            }
        } catch (Throwable $e) {
            Notification::make()->danger()->title(__('The folder could not be read'))->body($e->getMessage())->persistent()->send();

            return;
        }

        Notification::make()->success()->title(__('Forwarding to :to', ['to' => $data['forward_to']]))->send();
    }

    /**
     * The folder's newest emails, each with whether (and where) it went on.
     *
     * @return array<string, array<string, mixed>>
     */
    private function emails(): array
    {
        if ($this->emails !== null) {
            return $this->emails;
        }

        $mailbox = $this->mailSender();
        $this->readError = null;

        try {
            $emails = app(MailboxFolderForwarder::class)->latest($mailbox, $this->folder, self::SHOWN);
        } catch (Throwable $e) {
            $this->readError = $e->getMessage();

            return $this->emails = [];
        }

        $forwarded = MailboxFolderForwarder::forwarded($mailbox, $this->folder, array_map(MailboxFolderForwarder::messageKey(...), $emails));

        return $this->emails = collect($emails)->mapWithKeys(function (array $email) use ($forwarded): array {
            $log = $forwarded[MailboxFolderForwarder::messageKey($email)] ?? null;

            return [(string) $email['uid'] => [
                ...$email,
                '__key' => (string) $email['uid'],
                'forwarded' => match (true) {
                    $log === null => null,
                    $log->forwarded_at !== null => __('To :to, :at', ['to' => $log->forwarded_to, 'at' => Dates::dateTime($log->forwarded_at)]),
                    default => __('Not forwarded to :to: :error', ['to' => $log->forwarded_to, 'error' => (string) $log->error]),
                },
                'forward_failed' => $log !== null && $log->forwarded_at === null,
            ]];
        })->all();
    }

    /**
     * @return array<string, string>
     */
    private function folders(): array
    {
        try {
            $folders = app(MailboxFolderForwarder::class)->folders($this->mailSender());
        } catch (Throwable $e) {
            $this->readError = $e->getMessage();
            $folders = [];
        }

        // The one chosen, even when the mailbox could not be read.
        return $folders + [$this->folder => $this->folderName()];
    }

    private function folderName(): string
    {
        return strcasecmp($this->folder, 'INBOX') === 0 ? __('Inbox') : $this->folder;
    }

    private function mailSender(): ?MailSender
    {
        return once(fn (): ?MailSender => MailSender::query()->where('folders_only', true)->firstWhere('key', $this->mailbox));
    }

    private function forward(): ?MailboxFolderForward
    {
        $mailbox = $this->mailSender();

        return $mailbox ? MailboxFolderForward::query()->where('mail_sender_id', $mailbox->getKey())->where('folder', $this->folder)->first() : null;
    }
}
