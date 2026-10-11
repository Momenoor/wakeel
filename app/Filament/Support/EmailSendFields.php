<?php

namespace App\Filament\Support;

use App\Models\EmailTemplate;
use App\Models\Matter;
use App\Models\MatterLetter;
use App\Models\Setting;
use App\Services\MMS\MatterOneDriveExplorer;
use App\Services\MMS\SenderMailer;
use App\Support\Addresses;
use App\Support\EmailGrouping;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Illuminate\Support\Facades\Storage;
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

    /** The mailbox letters, minutes and bulk mail start from (Settings → Email). */
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
     * The default mailbox: the one set for letters and mail (Settings →
     * Email), else the system's own, else the first.
     */
    public static function defaultSender(): ?string
    {
        $options = SenderMailer::options();

        foreach ([Setting::get(self::DEFAULT_SENDER), Setting::get('mail_sender_key')] as $key) {
            if (filled($key) && array_key_exists($key, $options)) {
                return $key;
            }
        }

        return array_key_first($options);
    }

    /**
     * Copied in: the matter's experts (System Settings) and the chosen
     * email template's own list; changed per send.
     */
    public static function cc(): TagsInput
    {
        return TagsInput::make('cc')
            ->label(__('CC'))
            ->placeholder('name@example.com')
            ->helperText(__('The matter\'s experts chosen in System Settings and the email template\'s own CC are put here; remove any you don\'t want.'))
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
}
