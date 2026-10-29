<?php

namespace App\Filament\Mms\Pages;

use App\Filament\Concerns\HasModuleGate;
use App\Filament\Mms\Resources\MailSenders\MailSenderResource;
use App\Filament\Shared\Clusters\Settings;
use App\Models\MailboxFolderForward;
use App\Models\MailSender;
use App\Services\MMS\MailboxFolderForwarder;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Throwable;

/**
 * Mailbox folders (its own module; the super administrator's alone): mailboxes read over IMAP (a cPanel one, Yahoo,
 * Gmail) kept here alone — not among the mail senders, never sent from nor shared — each folder of them forwarded
 * on to an address of its own (MailboxFolderEmails), and, as chosen, one read for the matters' emails.
 */
class MailboxFolders extends Page implements HasTable
{
    use HasModuleGate;
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFolderOpen;

    protected static ?int $navigationSort = 21;

    protected static ?string $cluster = Settings::class;

    public static function moduleGateKey(): string
    {
        return MailboxFolderForwarder::MODULE;
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Communication');
    }

    public static function getNavigationLabel(): string
    {
        return __('Mailbox folders');
    }

    public function getTitle(): string
    {
        return __('Mailbox folders');
    }

    public function getSubheading(): ?string
    {
        return __('Mailboxes kept here alone — not among the mail senders, never sent from nor shared. Forward any of a mailbox\'s folders to an address of its own: for a mailbox that cannot forward with conditions (Yahoo), a filter there moves the emails you want into a folder.');
    }

    public static function canAccess(): bool
    {
        return static::isModuleEnabled() && (auth()->user()?->isSuperAdmin() ?? false);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(MailSender::query()->where('folders_only', true)->with('folderForwards'))
            ->headerActions([
                Action::make('add')
                    ->label(__('Add mailbox'))
                    ->icon(Heroicon::OutlinedPlus)
                    ->fillForm(['driver' => MailSender::SMTP, 'collects' => false])
                    ->schema(fn (): array => $this->mailboxForm(null))
                    ->action(fn (array $data) => $this->add($data)),
            ])
            ->columns([
                TextColumn::make('name')->label(__('Mailbox'))->weight('bold')->searchable()
                    ->description(fn (MailSender $record): string => (string) $record->address),
                TextColumn::make('inbox_folder')->label(__('Read for the matters\' emails'))
                    ->state(fn (MailSender $record): string => ! $record->collects ? __('No') : ($record->inbox_folder ?: __('Inbox'))),
                TextColumn::make('forwards')
                    ->label(__('Folders forwarded'))
                    ->state(fn (MailSender $record): array => $record->folderForwards
                        ->map(fn (MailboxFolderForward $forward): string => $forward->folder.' → '.$forward->forward_to.($forward->is_active ? '' : ' ('.__('paused').')'))
                        ->all())
                    ->listWithLineBreaks()
                    ->placeholder('—'),
            ])
            ->recordActions([
                Action::make('folders')
                    ->label(__('Folders'))
                    ->icon(Heroicon::OutlinedFolderOpen)
                    ->url(fn (MailSender $record): string => MailboxFolderEmails::getUrl(['mailbox' => $record->key])),
                Action::make('edit')
                    ->label(__('Edit'))
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->color('gray')
                    ->fillForm(fn (MailSender $record): array => [...$record->only(['address', 'name', 'host', 'port', 'encryption', 'username', 'collects', 'keep_all_inbox', 'inbox_folder']), 'driver' => MailSender::SMTP])
                    ->schema(fn (MailSender $record): array => $this->mailboxForm($record))
                    ->action(function (MailSender $record, array $data): void {
                        $record->update(self::attributes($data));
                        Notification::make()->success()->title(__('Saved'))->send();
                    }),
                DeleteAction::make(),
            ]);
    }

    /**
     * A mailbox of the module: its address (where it lives found from it), its server and login, and whether — and
     * which folder of it — is read for the matters' emails.
     *
     * @return list<Component>
     */
    private function mailboxForm(?MailSender $record): array
    {
        return [
            Hidden::make('driver'),
            Hidden::make('key')->dehydrated(false),
            Hidden::make('detection')->dehydrated(false),
            Grid::make(2)->schema([
                MailSenderResource::addressField(),
                TextInput::make('name')->label(__('Sender name'))->required()->maxLength(255),
                ...MailSenderResource::serverFields(),
                Toggle::make('collects')
                    ->label(__('Read for the matters\' emails'))
                    ->helperText(__('Off: its emails are only forwarded, folder by folder (Folders).'))
                    ->live()
                    ->columnSpanFull(),
                Select::make('inbox_folder')
                    ->label(__('Folder read'))
                    ->options(fn (): array => $record ? $this->folders($record) : [])
                    ->placeholder(__('Inbox'))
                    ->searchable()
                    ->visible(fn (Get $get): bool => $record !== null && (bool) $get('collects')),
                Toggle::make('keep_all_inbox')
                    ->label(__('Keep every email of this inbox'))
                    ->helperText(__('Off: only replies and emails from a matter\'s parties are kept from it.'))
                    ->visible(fn (Get $get): bool => (bool) $get('collects')),
            ]),
        ];
    }

    /**
     * A mailbox added to the module — one already among the mail senders (shared) taken out of them, as theirs alone.
     *
     * @param  array<string, mixed>  $data
     */
    private function add(array $data): void
    {
        $mailbox = MailSender::query()->whereNull('user_id')->where('address', $data['address'])->first();

        if ($mailbox === null) {
            $base = Str::slug(Str::before((string) $data['address'], '@'), '_') ?: 'mailbox';
            $key = $base;

            for ($n = 2; MailSender::query()->where('key', $key)->exists(); $n++) {
                $key = $base.'_'.$n;
            }

            $mailbox = new MailSender(['key' => $key]);
        }

        $mailbox->fill([...self::attributes($data), 'driver' => MailSender::SMTP, 'is_active' => true, 'folders_only' => true, 'sends' => false])->save();
        Notification::make()->success()->title(__('Saved'))->send();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function attributes(array $data): array
    {
        $attributes = Arr::only($data, ['address', 'name', 'host', 'port', 'encryption', 'username', 'password', 'collects', 'keep_all_inbox', 'inbox_folder']);
        $folder = trim((string) ($attributes['inbox_folder'] ?? ''));
        // The Inbox: none.
        $attributes['inbox_folder'] = $folder !== '' && strcasecmp($folder, 'INBOX') !== 0 ? $folder : null;
        $attributes['keep_all_inbox'] = (bool) ($attributes['keep_all_inbox'] ?? false);

        return $attributes;
    }

    /**
     * The mailbox's folders, read from it — none, when it can't be (said so).
     *
     * @return array<string, string>
     */
    private function folders(MailSender $mailbox): array
    {
        try {
            return app(MailboxFolderForwarder::class)->folders($mailbox);
        } catch (Throwable $e) {
            Notification::make()->danger()->title(__('The mailbox\'s folders could not be read'))->body($e->getMessage())->send();

            return [];
        }
    }
}
