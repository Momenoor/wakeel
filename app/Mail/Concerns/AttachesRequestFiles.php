<?php

namespace App\Mail\Concerns;

use App\Models\Attachment as StoredFile;
use App\Services\MMS\Letters\SharedAttachments;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A request's files with its email — as many as an email takes (Settings → Letters & emails, SharedAttachments),
 * in order; the rest left on the request's page, which the email links to, and counted for the email to say so
 * ($filesLeft). All of them attached, a report with its annexes ran past what PHP may hold (512 MB) while
 * Microsoft Graph wrote it out, and the job died with nobody told.
 */
trait AttachesRequestFiles
{
    /** How many of its files were left on the request's page, too large together for an email. */
    public int $filesLeft = 0;

    /**
     * @param  iterable<StoredFile>  $files
     * @return array<int, Attachment>
     */
    protected function requestFiles(iterable $files): array
    {
        $room = SharedAttachments::limitBytes();
        $attached = [];
        $this->filesLeft = 0;

        foreach ($files as $file) {
            if (! $file->fileExists()) {
                Log::warning('Attachment file not found: '.$file->path);

                continue;
            }

            $size = (int) ($file->size_bytes ?? 0) ?: (int) $file->storage()->size($file->path);
            if ($size > $room) {
                $this->filesLeft++;

                continue;
            }

            try {
                $attached[] = Attachment::fromStorageDisk($file->disk ?: 'public', $file->path)
                    ->as($file->name)
                    ->withMime($file->storage()->mimeType($file->path) ?: 'application/octet-stream');
                $room -= $size;
            } catch (Throwable $e) {
                Log::error('Failed to attach file: '.$file->path.' Error: '.$e->getMessage());
            }
        }

        return $attached;
    }
}
