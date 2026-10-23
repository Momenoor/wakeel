<?php

namespace App\Support;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * The system's own documents that were kept on the public disk — emails' PDFs and the files they came with,
 * minutes' PDFs and their signed copies — on this server, or in Azure Blob Storage once new files go there
 * (Settings → Integrations), each under the same path. One is read from wherever it is: here first, else
 * Azure. Everything else on the public disk stays here: logos, letterheads, fonts, pictures in texts and
 * bulk mail's files are read from disk by the PDF and Word makers.
 *
 * Each opens at /storage/<path> through Wakeel (LegacyAttachmentPathController), for whoever may see it —
 * here or in Azure: the web server sends their folders to Wakeel (public/.htaccess) rather than serving the
 * file itself, which let anyone with the address open it.
 */
final class Documents
{
    /** This server's disk for them. */
    public const LOCAL = 'public';

    /** Where a new one is kept: Azure once new files go there, else here. */
    public static function disk(): string
    {
        return Integrations::newFilesInAzure() ? Integrations::AZURE_PUBLIC_DISK : self::LOCAL;
    }

    /** A new one kept; the disk it went to (an attachment row records it). */
    public static function put(string $path, string $contents): string
    {
        Storage::disk($disk = self::disk())->put($path, $contents);

        return $disk;
    }

    /** The storage holding it: here, else Azure; null when in neither. */
    public static function storage(?string $path): ?Filesystem
    {
        if (blank($path)) {
            return null;
        }

        foreach (self::disks() as $disk) {
            if (Storage::disk($disk)->exists($path)) {
                return Storage::disk($disk);
            }
        }

        return null;
    }

    /**
     * Whether it can be offered as a link: here — or, with Azure set, taken to be there (not asked file by
     * file while a list is drawn; one that is gone opens as "not found").
     */
    public static function linkable(?string $path): bool
    {
        return filled($path) && (Integrations::azureOn() || Storage::disk(self::LOCAL)->exists($path));
    }

    /** @param  string|list<string>  $paths  removed wherever they are */
    public static function delete(string|array $paths): void
    {
        foreach (self::disks() as $disk) {
            Storage::disk($disk)->delete($paths);
        }
    }

    /** @return list<string> */
    private static function disks(): array
    {
        return Integrations::azureOn() ? [self::LOCAL, Integrations::AZURE_PUBLIC_DISK] : [self::LOCAL];
    }
}
