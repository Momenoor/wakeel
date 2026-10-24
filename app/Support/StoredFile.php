<?php

namespace App\Support;

use AzureOss\Storage\BlobLaravel\AzureStorageBlobAdapter;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Mime\MimeTypes;

/**
 * A stored file sent to the browser in one reading of it. The storage's own response() asks first whether it
 * is there, then its type, then its size, then reads it — four requests to Azure Blob Storage, about a second
 * each; what is known already (its type and size, kept with it) is not asked again, and "is it there" is
 * answered by opening it.
 *
 * Opened in the browser itself (a page, a frame, an image) from storage that gives links of its own (Azure): sent
 * there by a link good for a few minutes, made here without asking Azure anything — the file goes straight from
 * Azure to the browser, not through this server, which had waited a second or more for it each time. Fetched by
 * the page's own script (the PDF viewer, which may not read from another site), it is still read here.
 */
final class StoredFile
{
    /** How long a link straight to the storage is good for: opened at once, then useless. */
    public const LINK_MINUTES = 10;

    /** What the browser opens by itself (Sec-Fetch-Dest) — not a script's fetch, which another site's file would fail. */
    private const OPENED = ['document', 'iframe', 'frame', 'embed', 'object', 'image', 'audio', 'video'];

    public static function response(Filesystem $disk, string $path, string $name, bool $inline = true, ?string $mime = null, ?int $size = null, array $headers = []): StreamedResponse|RedirectResponse
    {
        $mime = $mime ?: self::byExtension($path);
        $disposition = HeaderUtils::makeDisposition($inline ? 'inline' : 'attachment', $name, str_replace('%', '', Str::ascii($name)));

        if ($link = self::link($disk, $path, $mime, $disposition)) {
            return redirect()->away($link);
        }

        $stream = rescue(fn () => $disk->readStream($path), null, report: false);
        abort_unless(is_resource($stream), 404);

        $response = new StreamedResponse(function () use ($stream): void {
            fpassthru($stream);
            fclose($stream);
        }, 200, $headers);

        $response->headers->set('Content-Type', $mime);

        if ($size) {
            $response->headers->set('Content-Length', (string) $size);
        }

        $response->headers->set('Content-Disposition', $disposition);

        return $response;
    }

    /** A link straight to it, when the storage makes them and the browser opens it itself; else null. */
    private static function link(Filesystem $disk, string $path, string $mime, string $disposition): ?string
    {
        if (! in_array(request()->headers->get('Sec-Fetch-Dest'), self::OPENED, true)
            // Azure only: a local disk's own links would go to /storage/, which has its own checks.
            || ! $disk instanceof AzureStorageBlobAdapter || ! $disk->providesTemporaryUrls()) {
            return null;
        }

        return rescue(fn (): string => $disk->temporaryUrl($path, now()->addMinutes(self::LINK_MINUTES), [
            // As this server would have sent it: its type, its name, shown or saved — encoded, as the
            // Azure library signs each decoded (an Arabic name's "%D9…" otherwise signed as something else).
            'httpHeaders' => ['contentType' => rawurlencode($mime), 'contentDisposition' => rawurlencode($disposition)],
        ]), null);
    }

    /** Its type by its name alone (no reading it): "a.pdf" → application/pdf. */
    private static function byExtension(string $path): string
    {
        return MimeTypes::getDefault()->getMimeTypes(strtolower(pathinfo($path, PATHINFO_EXTENSION)))[0] ?? 'application/octet-stream';
    }
}
