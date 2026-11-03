<?php

namespace App\Filament\Support;

use App\Jobs\ShareAttachments;
use App\Models\EmailTemplate;
use App\Models\MailSender;
use App\Models\Matter;
use App\Models\MatterContact;
use App\Models\MatterLetter;
use App\Models\Setting;
use App\Services\MMS\Letters\SharedAttachments;
use App\Services\MMS\MatterContactsExport;
use App\Services\MMS\MatterOneDriveExplorer;
use App\Services\MMS\MatterOneDriveFolders;
use App\Services\MMS\SenderMailer;
use App\Support\Addresses;
use App\Support\Branding;
use App\Support\EmailGrouping;
use App\Support\RequestMemo;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * The fields every "send by email" screen shares — letters, minutes: who
 * is copied in, how the emails go (EmailGrouping), files added to this
 * send — with what they start from and what's done with them once sent.
 */
final class EmailSendFields
{
    private const DISK = 'local';

    /** @var list<string> the OneDrive files fetched for this send */
    private static array $fetched = [];

    /** The mailbox letters, minutes and bulk mail start from (Settings → Letters & emails). */
    public const DEFAULT_SENDER = 'default_send_sender_key';

    /**
     * The mailbox a send goes from — chosen each time, starting from the
     * default (defaultSender()).
     */
    public static function sender(string $name = 'sender'): Select
    {
        return Select::make($name)
            ->label(__('Send from'))
            ->options(fn (): array => SenderMailer::options())
            ->required()
            ->live();
    }

    /**
     * The default mailbox: the user's own, else the one set for letters and mail (Settings →
     * Letters & emails), else the system's own, else the first they may send from.
     */
    public static function defaultSender(): ?string
    {
        $options = SenderMailer::options();
        $own = collect(array_keys($options))->first(fn (string $key): bool => SenderMailer::ownerOf($key) === auth()->id());

        foreach ([$own, Setting::get(self::DEFAULT_SENDER), Setting::get('mail_sender_key')] as $key) {
            if (filled($key) && array_key_exists($key, $options)) {
                return $key;
            }
        }

        return array_key_first($options);
    }

    /**
     * Copied in: the matter's experts (Settings → Letters & emails) and the chosen
     * email template's own list; changed per send.
     */
    public static function cc(): TagsInput
    {
        return TagsInput::make('cc')
            ->label(__('CC'))
            ->placeholder('name@example.com')
            ->helperText(__('The matter\'s experts chosen in Settings → Letters & emails and the email template\'s own CC are put here; remove any you don\'t want.'))
            ->splitKeys(['Tab', ' ', ',', 'Enter'])
            ->nestedRecursiveRules(['email'])
            ->live();
    }

    /**
     * The CC a send starts with.
     *
     * @return list<string>
     */
    public static function startingCc(?Matter $matter, mixed $templateId): array
    {
        return Addresses::emails([...MatterLetter::ccEmails($matter), ...EmailTemplate::ccOf($templateId)]);
    }

    /**
     * The addresses the recipients brought into the matter (MatterContact)
     * — each copied in on the email to whoever brought it only; unticked:
     * not this time. Shown only when there are some.
     *
     * @param  list<string>  $to
     * @param  list<int>  $partyIds
     */
    public static function contactsCc(?Matter $matter, array $to, array $partyIds = []): CheckboxList
    {
        $options = MatterContact::options($matter?->getKey(), $to, $partyIds);

        return CheckboxList::make('contacts_cc')
            ->label(__('Also copied in, from their replies'))
            ->helperText(__('Addresses a recipient put in To or CC when replying on this matter — each is copied in on the email to that recipient only.'))
            ->options($options)
            ->bulkToggleable()
            ->visible($options !== []);
    }

    /**
     * All of them, to start ticked.
     *
     * @param  list<string>  $to
     * @param  list<int>  $partyIds
     * @return list<string>
     */
    public static function startingContactsCc(?Matter $matter, array $to, array $partyIds = []): array
    {
        return array_keys(MatterContact::options($matter?->getKey(), $to, $partyIds));
    }

    /**
     * The matter's other contacts to send to as well — everyone on its Contacts tab with an email, those it goes to
     * already and the office's own mailboxes left out: each address an option, named as the tab names them.
     *
     * @param  list<string>  $exclude  addresses it goes to already
     */
    public static function alsoTo(?Matter $matter, array $exclude = []): CheckboxList
    {
        $options = self::contactOptions($matter, $exclude);

        return CheckboxList::make('also_to')
            ->label(__('Also to'))
            ->helperText(__('The matter\'s other contacts: each ticked gets the email too — with everyone when sent to all at once, else an email of their own.'))
            ->options($options)
            ->columns(2)
            ->bulkToggleable()
            ->live()
            ->visible($options !== []);
    }

    /**
     * Those ticked, each named as the Contacts tab names them — for the mailer.
     *
     * @param  list<string>  $emails
     * @return list<array{email: string, name: string}>
     */
    public static function alsoToNamed(?Matter $matter, array $emails): array
    {
        $options = self::contactOptions($matter, []);

        return array_values(array_map(
            fn (string $email): array => ['email' => $email, 'name' => trim((string) strstr($options[mb_strtolower($email)] ?? '', ' — ', true))],
            array_values(array_unique(Addresses::emails($emails))),
        ));
    }

    /**
     * @param  list<string>  $exclude
     * @return array<string, string> address => "name — address (capacity)"
     */
    private static function contactOptions(?Matter $matter, array $exclude): array
    {
        if (! $matter) {
            return [];
        }

        $exclude = array_map('mb_strtolower', Addresses::emails($exclude));
        $options = [];
        // The tab's rows, read once a request: the field asks for them as it is drawn, and when sent.
        $rows = RequestMemo::remember('matter-contact-rows.'.$matter->getKey(), fn (): array => (new MatterContactsExport($matter))->rows());

        foreach ($rows as $row) {
            foreach ((array) $row['own'] as $email) {
                $key = mb_strtolower($email);

                if (in_array($key, $exclude, true) || isset($options[$key]) || MatterContact::isOurs($email)) {
                    continue;
                }

                $options[$key] = trim((string) $row['name']).' — '.$email.($row['capacity'] !== '' ? ' ('.$row['capacity'].')' : '');
            }
        }

        return $options;
    }

    public static function grouping(): Radio
    {
        return Radio::make('grouping')
            ->label(__('How to send'))
            ->options(EmailGrouping::options())
            ->descriptions(EmailGrouping::descriptions())
            ->required()
            ->live();
    }

    /** The most a file added to a send may be, in KB (300 MB): past what an email takes, it goes by link. */
    public const MAX_UPLOAD_KB = 307200;

    /**
     * Files of this send's own, beside the letter or minutes.
     *
     * @param  ?Closure  $afterStateUpdated  what to do once a file is added (shareWhenOver())
     */
    public static function attachments(string $directory, string $helperText, ?Closure $afterStateUpdated = null): FileUpload
    {
        return FileUpload::make('attachments')
            ->label(__('More attachments'))
            ->helperText($helperText.' '.__('Up to :size a file; past what an email takes (Settings → Letters & emails) the files go by link.', ['size' => '300 MB']))
            ->multiple()
            ->disk(self::DISK)
            ->directory($directory)
            ->storeFileNamesIn('attachment_names')
            ->maxSize(self::MAX_UPLOAD_KB)
            ->live()
            ->afterStateUpdated($afterStateUpdated);
    }

    /**
     * Files from the matter's OneDrive folder (OneDriveFilePicker).
     *
     * @param  ?Closure  $afterStateUpdated  what to do once a file is picked (shareWhenOver())
     */
    public static function oneDriveFiles(?Matter $matter, ?Closure $afterStateUpdated = null): Select
    {
        return OneDriveFilePicker::field($matter)
            ->helperText(__('Files from this matter\'s OneDrive folder, attached as they are now.'))
            ->afterStateUpdated($afterStateUpdated);
    }

    /**
     * For a file added or picked: the files added shared by link at once — as Get the link now does — once they
     * come to more than an email takes (SharedAttachments::LIMIT), when the matter has a OneDrive folder.
     *
     * @param  Model  $record  the letter or minutes, with shared_files
     * @param  string  $folderName  the folder the files go in, as those with the link see it
     * @param  Closure(Get): list<string>  $to  whom the email goes to, for the folder
     */
    public static function shareWhenOver(?Matter $matter, Model $record, string $folderName, Closure $to): Closure
    {
        return function (Get $get, Set $set) use ($matter, $record, $folderName, $to): void {
            if ($matter === null || MatterOneDriveFolders::folderFor($matter) === null) {
                return;
            }

            if (SharedAttachments::over(self::addedBytes($matter, (array) ($get('attachments') ?? []), (array) ($get('onedrive_files') ?? [])))) {
                self::share($matter, $record, $folderName, $to, $get, $set, byItself: true);
            }
        };
    }

    /**
     * @param  list<string>  $picked
     * @return list<string>
     */
    public static function oneDriveNames(?Matter $matter, array $picked): array
    {
        return OneDriveFilePicker::names($matter, $picked);
    }

    /**
     * What the files added come to — the uploads as the form holds them (still being uploaded, or stored), and
     * the OneDrive files picked — for the send screen to say when they will go by link (SharedAttachments).
     *
     * @param  list<TemporaryUploadedFile|string>  $attachments
     * @param  list<string>  $picked
     */
    public static function addedBytes(?Matter $matter, array $attachments, array $picked): int
    {
        $uploaded = array_sum(array_map(fn ($file): int => $file instanceof TemporaryUploadedFile
            ? (int) $file->getSize()
            : (int) (Storage::disk(self::DISK)->exists((string) $file) ? Storage::disk(self::DISK)->size((string) $file) : 0), array_values($attachments)));

        return $uploaded + array_sum(array_column(OneDriveFilePicker::details($matter, array_values($picked)), 'size'));
    }

    /**
     * What the send screen says of the files: those shared by link already (the letter's or minutes' shared_files),
     * else that those added will go by link — null when nothing is to be said.
     *
     * @param  ?array{names: list<string>}  $existing
     */
    public static function sharedNote(?Matter $matter, array $data, ?array $existing = null): ?string
    {
        return SharedAttachments::warning(self::addedBytes($matter, (array) ($data['attachments'] ?? []), (array) ($data['onedrive_files'] ?? [])), $existing);
    }

    /**
     * The files added shared by link now, before sending, to copy the link into the covering email or the letter
     * by hand: "Get the link now" puts the files added (uploaded, or picked from OneDrive) in the folder — in the
     * background (ShareAttachments), a progress bar following each file copied and the link made — and shows the
     * link, to copy; remembered on the letter or minutes (shared_files), the send reuses it, and files added over
     * the limit later go into the same folder. "Forget the link" drops it (the folder stays in OneDrive).
     *
     * @param  Model  $record  the letter or minutes, with shared_files
     * @param  string  $folderName  the folder the files go in, as those with the link see it
     * @param  Closure(Get): list<string>  $to  whom the email goes to, for the folder
     * @return list<Component>
     */
    public static function sharedLink(?Matter $matter, Model $record, string $folderName, Closure $to): array
    {
        return [
            Actions::make([
                Action::make('shareNow')
                    ->label(__('Get the link now'))
                    ->icon('heroicon-o-link')
                    ->color('gray')
                    ->visible(fn (): bool => $matter !== null && MatterOneDriveFolders::folderFor($matter) !== null)
                    ->action(fn (Get $get, Set $set) => self::share($matter, $record, $folderName, $to, $get, $set)),
                Action::make('forgetLink')
                    ->label(__('Forget the link'))
                    ->color('gray')
                    ->link()
                    ->visible(fn (): bool => filled($record->shared_files))
                    ->requiresConfirmation()
                    ->modalHeading(__('Forget the link'))
                    ->modalDescription(__('The link is dropped, and the folder the files were put in is removed from OneDrive (to its owner\'s recycle bin). The files added again will be shared anew.'))
                    ->action(function () use ($record): void {
                        $removed = app(SharedAttachments::class)->forget($record);

                        Notification::make()->success()
                            ->title($removed ? __('The link is forgotten and its folder removed from OneDrive') : __('The link is forgotten'))
                            ->send();
                    }),
            ])->key('shared_link'),
            // How far the job has got, asked again every two seconds while it runs.
            Placeholder::make('share_progress')
                ->hiddenLabel()
                ->content(fn (): HtmlString => new HtmlString(SharedAttachments::progressBar(SharedAttachments::progress($record))))
                ->extraAttributes(fn (): array => SharedAttachments::running($record) ? ['wire:poll.2s' => ''] : [])
                ->visible(fn (): bool => SharedAttachments::progressBar(SharedAttachments::progress($record)) !== ''),
            TextEntry::make('shared_link')
                ->label(__('Files shared by link'))
                ->state(fn (): string => (string) ($record->fresh()?->shared_files['url'] ?? ''))
                ->helperText(fn (): string => implode(' · ', (array) ($record->fresh()?->shared_files['names'] ?? [])))
                ->copyable()
                ->copyMessage(__('Copied'))
                ->icon('heroicon-o-link')
                ->extraAttributes(['dir' => 'ltr', 'style' => 'word-break: break-all;'])
                ->visible(fn (): bool => filled($record->fresh()?->shared_files['url'] ?? null)),
        ];
    }

    /**
     * The files added shared by link now: staged (stage()), the job queued, the fields emptied (shared: not to be
     * attached as well), and said — done already (no queue worker: run at once), under way, or failed.
     *
     * @param  Closure(Get): list<string>  $to  whom the email goes to, for the folder
     * @param  bool  $byItself  not Get the link now pressed, but the files added come to more than an email takes
     */
    public static function share(?Matter $matter, Model $record, string $folderName, Closure $to, Get $get, Set $set, bool $byItself = false): void
    {
        [$directory, $staged] = self::stage($matter, [
            'attachments' => $get('attachments'), 'attachment_names' => $get('attachment_names'), 'onedrive_files' => $get('onedrive_files'),
        ]);

        if ($staged === []) {
            if (! $byItself) {
                Notification::make()->warning()->title(__('Add or pick the files to share first.'))->send();
            }

            return;
        }

        SharedAttachments::setProgress($record, SharedAttachments::QUEUED, 0, count($staged));
        ShareAttachments::dispatch($record, $staged, $directory, $folderName, $to($get));
        $set('attachments', []);
        $set('onedrive_files', []);

        $title = $byItself ? __('More than an email takes: the files are shared by link') : __('The files are shared by link');
        $progress = SharedAttachments::progress($record);
        if (($progress['step'] ?? null) === SharedAttachments::DONE) {
            Notification::make()->success()
                ->title($title)
                ->body(__('Copy the link below into the covering email or the letter where you want it; left out, it goes in a note at the end of the email.').(($record->fresh()->shared_files['anyone'] ?? true) ? '' : ' '.__('Microsoft 365 allows no links for anyone here: the link to the files opens for the office\'s own accounts only.')))
                ->send();
        } elseif (($progress['step'] ?? null) === SharedAttachments::FAILED) {
            Notification::make()->danger()->title(__('The files could not be shared'))->body((string) $progress['error'])->send();
        } else {
            Notification::make()->info()->title($byItself ? $title : __('Sharing the files…'))->body(__('The files are being copied to the matter\'s OneDrive folder; the link shows below when it is ready.'))->send();
        }
    }

    /**
     * The files added (addedNow()) staged in a folder of their own for the sharing job, which removes it when
     * done: each copied there under its position and name, the OneDrive files fetched let go once copied.
     *
     * @param  array<string, mixed>  $data
     * @return array{0: string, 1: list<array{path: string, name: string}>} the folder on the local disk, and the files staged in it
     */
    public static function stage(?Matter $matter, array $data): array
    {
        $directory = 'shared-attachments/'.Str::uuid();
        $disk = Storage::disk(self::DISK);
        $staged = [];
        foreach (self::addedNow($matter, $data) as $i => $file) {
            if (! is_file($file['path'])) {
                continue;
            }

            $path = $directory.'/'.$i.'-'.MatterOneDriveExplorer::cleanName($file['name']);
            $disk->put($path, (string) file_get_contents($file['path']));
            $staged[] = ['path' => $disk->path($path), 'name' => $file['name']];
        }
        self::forget([]);

        return [$directory, $staged];
    }

    /**
     * The files added, as they are now — an upload still only in the browser's temporary store too — and the
     * OneDrive files picked, fetched (kept until forget()): for sharing before the send.
     *
     * @param  array<string, mixed>  $data
     * @return list<array{path: string, name: string}>
     */
    public static function addedNow(?Matter $matter, array $data): array
    {
        $attachments = array_values((array) ($data['attachments'] ?? []));
        $temporary = array_values(array_filter($attachments, fn ($file): bool => $file instanceof TemporaryUploadedFile));

        return [
            ...array_map(fn (TemporaryUploadedFile $file): array => ['path' => (string) $file->getRealPath(), 'name' => $file->getClientOriginalName()], $temporary),
            ...self::uploaded([...$data, 'attachments' => array_values(array_filter($attachments, 'is_string'))], $matter),
        ];
    }

    /**
     * The files added — uploaded, and from OneDrive (fetched now, kept until
     * forget()) — as the mailers take them.
     *
     * @param  array<string, mixed>  $data
     * @return list<array{path: string, name: string}>
     */
    public static function uploaded(array $data, ?Matter $matter = null): array
    {
        $files = array_map(fn (string $path): array => [
            'path' => Storage::disk(self::DISK)->path($path),
            'name' => (string) ($data['attachment_names'][$path] ?? basename($path)),
        ], array_values((array) ($data['attachments'] ?? [])));

        $picked = array_values((array) ($data['onedrive_files'] ?? []));
        if ($picked === [] || ! $matter) {
            return $files;
        }

        // Fetched now (a failure stops the send, the screen left open),
        // kept as files until forget().
        $disk = Storage::disk(self::DISK);

        foreach (OneDriveFilePicker::fetch($matter, $picked) as $file) {
            $path = 'onedrive-attachments/'.Str::uuid().'/'.MatterOneDriveExplorer::cleanName($file['name']);
            $disk->put($path, $file['contents']);
            self::$fetched[] = $path;
            $files[] = ['path' => $disk->path($path), 'name' => $file['name']];
        }

        return $files;
    }

    /**
     * Sent: the uploads, and the files fetched from OneDrive, were for this
     * send only.
     *
     * @param  array<string, mixed>  $data
     */
    public static function forget(array $data): void
    {
        $disk = Storage::disk(self::DISK);
        $disk->delete(array_values((array) ($data['attachments'] ?? [])));

        foreach (self::$fetched as $path) {
            $disk->deleteDirectory(dirname($path));
        }

        self::$fetched = [];
    }

    /**
     * An email's preview, as it will go: to whom, its subject and files, then the email itself as the mail
     * program shows it, its placeholders filled. On the letters' and the minutes' send screens.
     *
     * @param  array{subject: string, html: string, rtl: bool}  $preview
     * @param  list<string>  $attachments
     * @param  ?string  $sharedNote  that the files will go by link, when they will (sharedNote())
     * @return list<Component>
     */
    public static function previewEntries(string $to, string $cc, array $preview, array $attachments, ?string $sharedNote = null): array
    {
        return [
            TextEntry::make('preview_to')->label(__('To'))->inlineLabel()->state($to ?: '—')->extraAttributes(['dir' => 'auto']),
            TextEntry::make('preview_cc')->label(__('CC'))->inlineLabel()->state($cc)->extraAttributes(['dir' => 'ltr'])->visible(filled($cc)),
            TextEntry::make('preview_subject')->label(__('Subject'))->inlineLabel()->state($preview['subject'])->extraAttributes(['dir' => 'auto']),
            TextEntry::make('preview_attachments')->label(__('Attachments'))->inlineLabel()->state(implode(' · ', $attachments))->extraAttributes(['dir' => 'auto'])->visible($attachments !== []),
            TextEntry::make('preview_shared')->hiddenLabel()->state((string) $sharedNote)->color('warning')->icon('heroicon-o-link')->visible(filled($sharedNote)),
            // The email itself, as the mail program shows it.
            TextEntry::make('preview_body')
                ->hiddenLabel()
                ->state(new HtmlString(MailSender::previewCss().$preview['html']))
                ->extraAttributes([
                    'class' => 'wk-mail',
                    'dir' => $preview['rtl'] ? 'rtl' : 'ltr',
                    'style' => 'padding: 1rem; background: #ffffff; color: #111827; border: 1px solid rgb(229 231 235); border-radius: 0.5rem;'
                        .' font-family: '.Branding::emailFontFamily().'; font-size: 15px; line-height: 1.7;'
                        .' text-align: '.($preview['rtl'] ? 'right' : 'left').'; max-height: 28rem; overflow: auto;',
                ]),
        ];
    }
}
