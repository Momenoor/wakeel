<?php

namespace App\Http\Controllers;

use App\Services\MMS\OneDriveClient;
use App\Services\MMS\PersonalOneDrive;
use App\Support\ScreenPermissions;
use Illuminate\Http\Request;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A PDF from the signed-in user's own OneDrive, for the Split PDF page to split in the browser. Asked for by
 * its id only — never by an address the browser sends — and looked up in their own drive alone. Passed on a
 * piece at a time as OneDrive sends it: nothing is kept on the server, nor held whole in its memory.
 */
class SplitPdfOneDriveController extends Controller
{
    /** How much is read from OneDrive and sent on at a time. */
    private const CHUNK = 1024 * 1024;

    public function __invoke(Request $request, string $item, PersonalOneDrive $drive, OneDriveClient $client): StreamedResponse
    {
        abort_unless($request->user()->can(ScreenPermissions::SPLIT_PDF), 403);

        try {
            $file = $drive->pdf($request->user(), $item);
            $body = $client->stream($file['downloadUrl']);
        } catch (RuntimeException $e) {
            abort(in_array($e->getCode(), [403, 404], true) ? $e->getCode() : 502, $e->getMessage());
        }

        // Whatever OneDrive called it, what comes must be a PDF: "%PDF-" in its first kilobyte.
        $head = self::read($body, 1024);
        abort_unless(str_contains($head, '%PDF-'), 403, __('This is not a PDF file.'));

        return new StreamedResponse(function () use ($head, $body): void {
            // A big file over a slow line takes longer than a page may.
            @set_time_limit(0);

            echo $head;

            while (! $body->eof()) {
                echo $body->read(self::CHUNK);
                flush();
            }

            $body->close();
        }, 200, [
            'Content-Type' => 'application/pdf',
            // For the page's progress only: the length itself is left to the transfer.
            'X-File-Size' => (string) ($file['size'] ?? ''),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    /** The first bytes, however few at a time the stream gives them. */
    private static function read(StreamInterface $body, int $length): string
    {
        $bytes = '';

        while (strlen($bytes) < $length && ! $body->eof()) {
            $bytes .= $body->read($length - strlen($bytes));
        }

        return $bytes;
    }
}
