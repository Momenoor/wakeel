<?php

namespace App\Services\MMS;

use App\Mail\LetterEmail;
use App\Models\Matter;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Every email sent from a matter, kept as it went — a PDF of the email
 * (who it was from and to, when, what was attached): with the matter's
 * attachments, and in its OneDrive folder's sent-emails subfolder (OneDrive
 * Settings; not when left empty). Letters, minutes and bulk mail alike.
 * Never stops the send it keeps.
 */
class SentEmailArchive
{
    /** The party's name in an email's file name — at most this long. */
    public const PARTY_LIMIT = 40;

    /** An email's whole file name, before ".pdf" — at most this long. */
    public const NAME_LIMIT = 120;

    public function __construct(private readonly MatterOneDriveFolders $folders) {}

    /**
     * An email's file name, sent or received alike — when, which way, the
     * party, what about: "2026-10-11 05.48 بريد صادر — شرطة دبي — JPA
     * 2026 986 1". The party's name and the whole kept short; nothing a
     * file name can't hold.
     */
    public static function emailName(bool $sent, string $party, string $about, ?CarbonInterface $at = null): string
    {
        $head = ($at ?? now())->format('Y-m-d H.i').' '.($sent ? __('Outgoing email') : __('Reply'));
        $party = Str::limit(trim((string) preg_replace('/\s+/u', ' ', $party)), self::PARTY_LIMIT, '…');
        $name = implode(' — ', array_filter([$head, $party, trim($about)], fn ($part) => $part !== ''));

        return MatterOneDriveExplorer::cleanName(Str::limit($name, self::NAME_LIMIT, '…'));
    }

    /**
     * A sent letter or minutes email, kept.
     *
     * @param  array<string, mixed>  $sender
     * @param  list<string>  $to
     * @param  list<string>  $cc
     */
    public function keepEmail(Matter $matter, LetterEmail $email, array $sender, string $names, array $to, array $cc, string $title, ?int $userId): void
    {
        try {
            // Its images from their files (in the email, they're embedded).
            $html = $email->letterHtml;
            foreach ($email->images as $token => $path) {
                $html = str_replace($token, $path, $html);
            }

            $pdf = EmailPdf::render(
                $email->emailSubject,
                $html,
                ['name' => (string) ($sender['name'] ?? ''), 'address' => (string) ($sender['address'] ?? '')],
                $names,
                $to,
                $cc,
                [],
                array_map(fn (array $file): array => ['name' => $file['name'], 'size' => strlen((string) $file['data'])], $email->files),
                now(),
            );

            $this->keep($matter, $pdf, $names, $title, $userId);
        } catch (Throwable $e) {
            Log::warning('Sent email not kept on its matter', ['matter' => $matter->getKey(), 'error' => $e->getMessage()]);
        }
    }

    /**
     * A sent email's PDF — named for its party (emailName()): with the
     * matter's attachments (unless it's kept elsewhere already — a bulk
     * mail's), and in the OneDrive sent-emails folder.
     */
    public function keep(Matter $matter, string $pdf, string $party, string $about, ?int $userId, bool $asAttachment = true): void
    {
        $this->keepFile($matter, self::emailName(true, $party, $about).'.pdf', $pdf, 'application/pdf', MatterOneDriveFolders::sentEmailsFolder(), $userId, $asAttachment);
    }

    /**
     * A file of an email — its PDF, or one it came with: with the matter's
     * attachments (unless kept elsewhere), and in this subfolder of its
     * OneDrive folder (none: not there).
     */
    public function keepFile(Matter $matter, string $name, string $contents, string $mime, string $subfolder, ?int $userId, bool $asAttachment = true): ?string
    {
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $path = null;

        if ($asAttachment) {
            try {
                $path = 'attachments/emails/'.$matter->getKey().'/'.now()->format('Ymd-His').'-'.Str::random(6).($extension !== '' ? '.'.$extension : '');
                Storage::disk('public')->put($path, $contents);

                $matter->attachments()->create([
                    'user_id' => $userId ?? auth()->id(),
                    'type' => 'correspondence',
                    'path' => $path,
                    'name' => $name,
                    'size' => strlen($contents),
                    'extension' => $extension,
                ]);
            } catch (Throwable $e) {
                Log::warning('Email file not kept with the matter\'s attachments', ['matter' => $matter->getKey(), 'error' => $e->getMessage()]);
                $path = null;
            }
        }

        $folder = trim($subfolder);
        if ($folder === '') {
            return $path;
        }

        try {
            // As named — a "/" in it no folder.
            $base = $extension !== '' ? Str::beforeLast($name, '.'.pathinfo($name, PATHINFO_EXTENSION)) : $name;
            $fileName = MatterOneDriveExplorer::cleanName($base).($extension !== '' ? '.'.$extension : '');
            $this->folders->uploadToSubfolder($matter, $folder, $fileName, $contents, $mime);
        } catch (Throwable $e) {
            Log::warning('Email file not saved in OneDrive', ['matter' => $matter->getKey(), 'error' => $e->getMessage()]);
        }

        return $path;
    }

    /**
     * A file of an email of no matter (a bulk mail campaign's reply), kept
     * in its own folder. Where it went.
     */
    public function keepLoose(string $folder, string $name, string $contents): string
    {
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $base = $extension !== '' ? Str::beforeLast($name, '.'.pathinfo($name, PATHINFO_EXTENSION)) : $name;
        $path = trim($folder, '/').'/'.MatterOneDriveExplorer::cleanName($base).($extension !== '' ? '.'.$extension : '');
        Storage::disk('public')->put($path, $contents);

        return $path;
    }
}
