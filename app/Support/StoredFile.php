<?php

namespace App\Support;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Mime\MimeTypes;

/**
 * A stored file sent to the browser in one reading of it. The storage's own response() asks first whether it
 * is there, then its type, then its size, then reads it — four requests to Azure Blob Storage, about a second
 * each; what is known already (its type and size, kept with it) is not asked again, and "is it there" is
 * answered by opening it.
 */
final class StoredFile
{
    public static function response(Filesystem $disk, string $path, string $name, bool $inline = true, ?string $mime = null, ?int $size = null, array $headers = []): StreamedResponse
    {
        $stream = rescue(fn () => $disk->readStream($path), null, report: false);
        abort_unless(is_resource($stream), 404);

        $response = new StreamedResponse(function () use ($stream): void {
            fpassthru($stream);
            fclose($stream);
        }, 200, $headers);

        $response->headers->set('Content-Type', $mime ?: self::byExtension($path));

        if ($size) {
            $response->headers->set('Content-Length', (string) $size);
        }

        $response->headers->set('Content-Disposition', $response->headers->makeDisposition(
            $inline ? 'inline' : 'attachment',
            $name,
            str_replace('%', '', Str::ascii($name)),
        ));

        return $response;
    }

    /** Its type by its name alone (no reading it): "a.pdf" → application/pdf. */
    private static function byExtension(string $path): string
    {
        return MimeTypes::getDefault()->getMimeTypes(strtolower(pathinfo($path, PATHINFO_EXTENSION)))[0] ?? 'application/octet-stream';
    }
}
