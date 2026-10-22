<?php

namespace App\Filament\Support;

use App\Models\EmailTemplate;
use App\Models\MailSender;
use App\Models\Matter;
use App\Models\MatterContact;
use App\Models\MatterLetter;
use App\Models\Setting;
use App\Services\MMS\MatterOneDriveExplorer;
use App\Services\MMS\SenderMailer;
use App\Support\Addresses;
use App\Support\Branding;
use App\Support\EmailGrouping;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Component;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

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

    public static function grouping(): Radio
    {
        return Radio::make('grouping')
            ->label(__('How to send'))
            ->options(EmailGrouping::options())
            ->descriptions(EmailGrouping::descriptions())
            ->required()
            ->live();
    }

    /** Files of this send's own, beside the letter or minutes. */
    public static function attachments(string $directory, string $helperText): FileUpload
    {
        return FileUpload::make('attachments')
            ->label(__('More attachments'))
            ->helperText($helperText)
            ->multiple()
            ->disk(self::DISK)
            ->directory($directory)
            ->storeFileNamesIn('attachment_names')
            ->maxSize(20480)
            ->live();
    }

    /** Files from the matter's OneDrive folder (OneDriveFilePicker). */
    public static function oneDriveFiles(?Matter $matter): Select
    {
        return OneDriveFilePicker::field($matter)
            ->helperText(__('Files from this matter\'s OneDrive folder, attached as they are now.'));
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
     * @return list<Component>
     */
    public static function previewEntries(string $to, string $cc, array $preview, array $attachments): array
    {
        return [
            TextEntry::make('preview_to')->label(__('To'))->inlineLabel()->state($to ?: '—')->extraAttributes(['dir' => 'auto']),
            TextEntry::make('preview_cc')->label(__('CC'))->inlineLabel()->state($cc)->extraAttributes(['dir' => 'ltr'])->visible(filled($cc)),
            TextEntry::make('preview_subject')->label(__('Subject'))->inlineLabel()->state($preview['subject'])->extraAttributes(['dir' => 'auto']),
            TextEntry::make('preview_attachments')->label(__('Attachments'))->inlineLabel()->state(implode(' · ', $attachments))->extraAttributes(['dir' => 'auto'])->visible($attachments !== []),
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
