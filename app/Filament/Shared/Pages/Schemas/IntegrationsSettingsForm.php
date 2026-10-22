<?php

namespace App\Filament\Shared\Pages\Schemas;

use App\Jobs\CopyFilesToAzure;
use App\Services\Attachments\CopyToAzure;
use App\Services\MMS\CollectedEmailUndo;
use App\Services\MMS\MatterReplyCollector;
use App\Support\Integrations;
use BezhanSalleh\FilamentShield\Support\Utils;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Settings → Integrations (the sections of that page): this office's own
 * Microsoft 365, Pusher, cron-job.org link, WhatsApp and Azure Blob Storage. Each is off until it
 * is filled in. Only the super admin sees the page — it holds the office's
 * keys. Saved secrets never come back to the page; a blank secret field keeps
 * the saved one.
 */
class IntegrationsSettingsForm
{
    /** How long "Copy the files to Azure" copies a press when nothing runs the queue: well within a request's time. */
    private const COPY_SECONDS = 20;

    /**
     * @return array<int, Section>
     */
    public static function sections(): array
    {
        return [
            Section::make('Microsoft 365')
                ->description(__('One app registration in your Microsoft 365 (Entra ID) for OneDrive folders, the Outlook calendar and Microsoft 365 mailboxes. Without it these stay off; mailboxes can still send through cPanel (SMTP).'))
                ->icon(Heroicon::Cloud)
                ->headerActions([self::turnOff('microsoft', [Integrations::MS_TENANT, Integrations::MS_CLIENT, Integrations::MS_SECRET, Integrations::MS_CALENDAR])])
                ->columns(2)
                ->schema([
                    self::status('microsoft', fn (): bool => Integrations::microsoftOn()),
                    TextInput::make(Integrations::MS_TENANT)->label(__('Tenant ID'))->extraInputAttributes(['dir' => 'ltr']),
                    TextInput::make(Integrations::MS_CLIENT)->label(__('Client ID'))->extraInputAttributes(['dir' => 'ltr']),
                    self::secret(Integrations::MS_SECRET, __('Client secret')),
                    TextInput::make(Integrations::MS_CALENDAR)
                        ->label(__('Calendar mailbox'))
                        ->email()
                        ->helperText(__('The Outlook calendar sessions are kept in. Empty: no calendar sync.'))
                        ->extraInputAttributes(['dir' => 'ltr']),
                ]),

            Section::make('Pusher')
                ->description(__('Live updates — notifications, chat and counts as they happen. Without it pages check every few seconds instead.'))
                ->icon(Heroicon::Bolt)
                ->headerActions([self::turnOff('pusher', [Integrations::PUSHER_APP, Integrations::PUSHER_KEY, Integrations::PUSHER_SECRET, Integrations::PUSHER_CLUSTER])])
                ->columns(2)
                ->schema([
                    self::status('pusher', fn (): bool => Integrations::pusherOn()),
                    TextInput::make(Integrations::PUSHER_APP)->label(__('App ID'))->extraInputAttributes(['dir' => 'ltr']),
                    TextInput::make(Integrations::PUSHER_KEY)->label(__('Key'))->extraInputAttributes(['dir' => 'ltr']),
                    self::secret(Integrations::PUSHER_SECRET, __('Secret')),
                    TextInput::make(Integrations::PUSHER_CLUSTER)->label(__('Cluster'))->placeholder('mt1')->extraInputAttributes(['dir' => 'ltr']),
                ]),

            Section::make(__('Scheduled tasks (cron-job.org)'))
                ->description(__('Sends queued emails and runs the daily tasks. Create a free cron-job.org job that opens the link below every minute. Without it emails are sent at once and scheduled tasks don\'t run.'))
                ->icon(Heroicon::Clock)
                ->headerActions([self::turnOff('cron', [Integrations::CRON_TOKEN])])
                ->schema([
                    self::status('cron', fn (): bool => Integrations::cronOn()),
                    self::secret(Integrations::CRON_TOKEN, __('Secret token'))
                        ->minLength(32)
                        ->live(onBlur: true)
                        ->suffixAction(
                            Action::make('generateCronToken')
                                ->label(__('Generate'))
                                ->icon(Heroicon::ArrowPath)
                                ->action(fn (Set $set) => $set(Integrations::CRON_TOKEN, Str::random(48))),
                        ),
                    // Whether the scheduler really runs: the automatic email check is one of its tasks (how the
                    // mailboxes are read: Settings → Communication → Collecting emails).
                    TextEntry::make('last_email_check')
                        ->label(__('Last automatic email check'))
                        ->state(fn (): string => MatterReplyCollector::lastRunSummary()),
                    TextEntry::make('cron_url')
                        ->label(__('Link for cron-job.org'))
                        ->state(function (Get $get): string {
                            $token = trim((string) $get(Integrations::CRON_TOKEN)) ?: Integrations::get(Integrations::CRON_TOKEN);

                            return strlen($token) >= 32 ? route('cron.run', ['token' => $token]) : __('Generate a token, then save.');
                        })
                        ->copyable()
                        ->extraAttributes(['dir' => 'ltr', 'style' => 'word-break: break-all;']),
                ]),

            Section::make('WhatsApp')
                ->description(__('Your WhatsApp Business number in Meta (WhatsApp → API Setup). The webhook\'s app secret is set under Templates → WhatsApp templates → Replies (webhook).'))
                ->icon(Heroicon::ChatBubbleLeftRight)
                ->headerActions([self::turnOff('whatsapp', [Integrations::WHATSAPP_PHONE, Integrations::WHATSAPP_TOKEN])])
                ->columns(2)
                ->schema([
                    self::status('whatsapp', fn (): bool => Integrations::whatsappOn()),
                    TextInput::make(Integrations::WHATSAPP_PHONE)->label(__('Phone number ID'))->extraInputAttributes(['dir' => 'ltr']),
                    self::secret(Integrations::WHATSAPP_TOKEN, __('Access token')),
                ]),

            Section::make('Azure Blob Storage')
                ->description(__('Your storage account in Azure (Storage account → Access keys) and a private container in it, where attached files, signatures and stamps, and the emails\' and minutes\' PDFs and files are kept instead of on this server. They open only through Wakeel, for whoever may see them. Logos, letterheads and fonts stay here.'))
                ->icon(Heroicon::ServerStack)
                ->headerActions([
                    self::testAzureAction(),
                    self::copyToAzureAction(),
                    self::removeLocalAction(),
                    // Not while files are kept there: they would no longer open.
                    self::turnOff('azure', [Integrations::AZURE_ACCOUNT, Integrations::AZURE_KEY, Integrations::AZURE_CONTAINER, Integrations::AZURE_ATTACHMENTS])
                        ->hidden(fn (): bool => CopyToAzure::anyInAzure()),
                ])
                ->columns(2)
                ->schema([
                    self::status('azure', fn (): bool => Integrations::azureOn()),
                    TextInput::make(Integrations::AZURE_ACCOUNT)->label(__('Storage account name'))->extraInputAttributes(['dir' => 'ltr']),
                    self::secret(Integrations::AZURE_KEY, __('Access key')),
                    TextInput::make(Integrations::AZURE_CONTAINER)->label(__('Container'))->placeholder('wakeel')->extraInputAttributes(['dir' => 'ltr']),
                    Toggle::make(Integrations::AZURE_ATTACHMENTS)
                        ->label(__('Keep new files in Azure'))
                        ->helperText(__('Those kept before stay on this server until copied ("Copy the files to Azure"); either way each opens from where it is.'))
                        ->formatStateUsing(fn ($state): bool => (bool) $state)
                        ->dehydrateStateUsing(fn ($state): string => $state ? '1' : '0')
                        ->columnSpanFull(),
                    // Where the files are, by kind.
                    TextEntry::make('azure_counts')
                        ->label(__('Where the files are'))
                        ->visible(fn (): bool => Integrations::azureOn())
                        ->state(fn (): array => collect(CopyToAzure::counts())
                            ->map(fn (array $count, string $kind): string => __(':kind: :azure in Azure, :here on this server', ['kind' => $kind, 'azure' => $count['azure'], 'here' => $count['here']]))
                            ->values()
                            ->all())
                        ->listWithLineBreaks()
                        ->columnSpanFull(),
                ]),
        ];
    }

    /** Whether the saved account can be written to and read from: a small file put, read back and deleted. */
    private static function testAzureAction(): Action
    {
        return Action::make('testAzure')
            ->label(__('Test connection'))
            ->icon(Heroicon::Signal)
            ->link()
            ->visible(fn (): bool => Integrations::azureOn())
            ->action(function (): void {
                $disk = Storage::disk(Integrations::AZURE_DISK);
                $path = 'wakeel-connection-test-'.Str::random(8).'.txt';

                try {
                    $disk->put($path, 'ok');
                    $read = $disk->get($path);
                    $disk->delete($path);
                    throw_unless($read === 'ok', \RuntimeException::class, __('The file written could not be read back.'));

                    Notification::make()->success()->title(__('Azure is connected: a file was written, read and deleted.'))->send();
                } catch (Throwable $e) {
                    Notification::make()->danger()->title(__('Azure could not be reached'))->body(Str::limit($e->getMessage(), 300))->persistent()->send();
                }
            });
    }

    /**
     * The files kept on this server copied to Azure: in the background, until all are (CopyFilesToAzure), told
     * in the bell when done — or, with nothing running the queue (no cron link), for some seconds a press.
     * Not in the page's transaction: each row is pointed at Azure as soon as its file is copied.
     */
    private static function copyToAzureAction(): Action
    {
        $background = fn (): bool => config('queue.default') !== 'sync';

        return Action::make('copyToAzure')
            ->label(__('Copy the files to Azure'))
            ->icon(Heroicon::CloudArrowUp)
            ->link()
            ->visible(fn (): bool => Integrations::azureOn())
            ->requiresConfirmation()
            ->modalIcon(Heroicon::CloudArrowUp)
            ->modalDescription(fn (): string => collect([
                CopyFilesToAzure::running() ? __('Copying in the background now.') : null,
                __('Files kept on this server: :count.', ['count' => CopyToAzure::remaining()]),
                $background()
                    ? __('They are copied in the background, a few each minute, and you are told in the bell when it is done.')
                    : __('Each press copies for about :seconds seconds (set the cron link to copy them all in the background).', ['seconds' => self::COPY_SECONDS]),
                __('Each file is checked against its original before it opens from Azure. Attached files, signatures and stamps keep their originals here; the emails\' and minutes\' files are moved (removed here once checked).'),
            ])->filter()->implode(' '))
            ->modalSubmitActionLabel(__('Copy'))
            ->databaseTransaction(false)
            ->action(function () use ($background): void {
                if ($background()) {
                    CopyFilesToAzure::start(auth()->id())
                        ? Notification::make()->success()->title(__('Copying in the background: you are told in the bell when it is done.'))->send()
                        : Notification::make()->warning()->title(__('A copy to Azure is already running. Try again in a minute.'))->send();

                    return;
                }

                $stats = app(CopyToAzure::class)->run(seconds: self::COPY_SECONDS);

                if ($stats === null) {
                    Notification::make()->warning()->title(__('A copy to Azure is already running. Try again in a minute.'))->send();

                    return;
                }

                $left = CopyToAzure::remaining();

                Notification::make()
                    ->title(__(':count files copied to Azure.', ['count' => $stats['copied']]))
                    ->body(collect([
                        $left ? __('Still on this server: :count — copy again.', ['count' => $left]) : null,
                        $stats['missing'] ? __('Not found on this server (left as they are): :count.', ['count' => $stats['missing']]) : null,
                        $stats['errors'] ? __('Not copied: :errors', ['errors' => implode(' | ', array_slice($stats['errors'], 0, 5))]) : null,
                    ])->filter()->implode(' '))
                    ->{$stats['failed'] ? 'warning' : 'success'}()
                    ->persistent()
                    ->send();
            });
    }

    /**
     * The copies left on this server of the files already in Azure removed, each once its copy there is checked
     * to be it — in the background (CopyFilesToAzure), told in the bell when done; with nothing running the
     * queue, for some seconds a press.
     */
    private static function removeLocalAction(): Action
    {
        return Action::make('removeLocalCopies')
            ->label(__('Remove the copies on this server'))
            ->icon(Heroicon::Trash)
            ->color('danger')
            ->link()
            ->visible(fn (): bool => Integrations::azureOn())
            ->requiresConfirmation()
            ->modalDescription(fn (): string => collect([
                CopyFilesToAzure::running() ? __('Copying in the background now.') : null,
                __('Files in Azure whose copy is still on this server: :count.', ['count' => count(CopyToAzure::leftHere())]),
                __('Each copy here is removed only once its copy in Azure is checked to be the same file. The files open from Azure as they do now.'),
            ])->filter()->implode(' '))
            ->modalSubmitActionLabel(__('Remove them'))
            ->databaseTransaction(false)
            ->action(function (): void {
                if (config('queue.default') !== 'sync') {
                    CopyFilesToAzure::start(auth()->id(), removeLocal: true)
                        ? Notification::make()->success()->title(__('Removing in the background: you are told in the bell when it is done.'))->send()
                        : Notification::make()->warning()->title(__('A copy to Azure is already running. Try again in a minute.'))->send();

                    return;
                }

                $stats = app(CopyToAzure::class)->removeLocal(self::COPY_SECONDS);

                if ($stats === null) {
                    Notification::make()->warning()->title(__('A copy to Azure is already running. Try again in a minute.'))->send();

                    return;
                }

                Notification::make()
                    ->title(__(':count copies removed from this server.', ['count' => $stats['removed']]))
                    ->body($stats['errors'] ? __('Not done: :errors', ['errors' => implode(' | ', array_slice($stats['errors'], 0, 5))]) : null)
                    ->{$stats['failed'] ? 'warning' : 'success'}()
                    ->persistent()
                    ->send();
            });
    }

    private static function status(string $name, Closure $on): TextEntry
    {
        return TextEntry::make('integration_status_'.$name)
            ->hiddenLabel()
            ->state(fn (): string => $on() ? __('Connected') : __('Off'))
            ->badge()
            ->color(fn (): string => $on() ? 'success' : 'gray')
            ->columnSpanFull();
    }

    /**
     * Clears the service's saved settings — off at once (a blank secret
     * field otherwise keeps the saved one). .env, if it has any, still counts.
     *
     * @param  list<string>  $keys
     */
    private static function turnOff(string $name, array $keys): Action
    {
        return Action::make('turnOff_'.$name)
            ->label(__('Turn off'))
            ->icon(Heroicon::Power)
            ->color('danger')
            ->link()
            ->requiresConfirmation()
            ->visible(fn (): bool => collect($keys)->contains(fn (string $key): bool => Integrations::get($key) !== ''))
            ->action(function (Set $set) use ($keys): void {
                foreach ($keys as $key) {
                    Integrations::forget($key);
                    $set($key, null);
                }

                Notification::make()->success()->title(__('Turned off'))->send();
            });
    }

    /**
     * The emails collected in the last hours undone, as if never collected — then read again from
     * the inboxes by the next check. Super admins only.
     */
    public static function undoCollectedAction(): Action
    {
        return Action::make('undoCollectedEmails')
            ->label(__('Undo recent emails'))
            ->icon(Heroicon::ArrowUturnLeft)
            ->color('danger')
            ->link()
            ->visible(fn (): bool => auth()->user()?->hasRole(Utils::getSuperAdminName()) ?? false)
            ->requiresConfirmation()
            ->modalHeading(__('Undo the emails collected recently?'))
            ->modalDescription(fn (): string => __('Emails collected in the last 2 hours: :count.', ['count' => CollectedEmailUndo::since(2)->count()]).' '
                .__("Their PDFs and files are removed from the system and the matters' OneDrive (its recycle bin), with their steps in the progress. Those that were unread in the mailbox before are made unread again; the others stay as they are. The next check collects them again."))
            ->schema([
                TextInput::make('hours')
                    ->label(__('Collected in the last (hours)'))
                    ->numeric()
                    ->integer()
                    ->minValue(1)
                    ->maxValue(72)
                    ->default(2)
                    ->required(),
            ])
            ->modalSubmitActionLabel(__('Undo them'))
            ->action(function (array $data): void {
                $result = app(CollectedEmailUndo::class)->undo((int) $data['hours']);

                Notification::make()
                    ->title(trans_choice('{0} No emails to undo.|{1} 1 email undone.|[2,*] :count emails undone.', $result['undone'], ['count' => $result['undone']]))
                    ->body(collect([
                        $result['unread'] ? __(':count made unread again in the mailbox.', ['count' => $result['unread']]) : null,
                        $result['errors'] ? __('Not undone: :errors', ['errors' => implode(' | ', $result['errors'])]) : null,
                    ])->filter()->implode(' '))
                    ->{$result['errors'] ? 'warning' : 'success'}()
                    ->persistent()
                    ->send();
            });
    }

    private static function secret(string $key, string $label): TextInput
    {
        return TextInput::make($key)
            ->label($label)
            ->password()
            ->revealable()
            ->placeholder(fn (): ?string => Integrations::get($key) !== '' ? __('Saved — leave empty to keep') : null)
            ->extraInputAttributes(['dir' => 'ltr']);
    }
}
