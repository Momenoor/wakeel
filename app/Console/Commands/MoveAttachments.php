<?php

namespace App\Console\Commands;

use App\Models\MatterEmail;
use App\Services\Attachments\FileCopy;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Takes the files attached before the private disk existed (kept on the public disk, where anyone with the
 * address could open them) to the private one, where they open only through the attachment controller.
 *
 * On the server, once, after the update that brings the private disk and its two migrations:
 *
 *     php artisan attachments:move --dry-run     (reports what it would do, changes nothing)
 *     php artisan attachments:move               (does it)
 *
 * Each file is copied (streamed, so a big one costs no memory) under the name the attachment service gives
 * a new one (<owner>/<year>/<owner id>/<uuid>.<ext>), checked against the original (size and sha256 — also
 * recorded on the row when it had none), the row is pointed at the copy, and only then is the public copy
 * deleted: a file is never deleted before its copy is verified. The old address is kept in `legacy_path`,
 * so a link to /storage/<old path> still opens the file for whoever may see it (a signed-in user).
 *
 * Safe to stop and run again: only rows still on the public disk are looked at. A file that is not there is
 * counted and left; one that fails is reported and left where it was (the exit code is then 1). A file that
 * an email of the matter also lists (matter_emails.files) stays on the public disk, where that email reads
 * it. Do it in small steps on shared hosting: --limit=500 --chunk=100.
 *
 * Backups must include storage/app/attachments from now on.
 */
class MoveAttachments extends Command
{
    protected $signature = 'attachments:move
        {--dry-run : Report what would be moved, change nothing}
        {--limit= : Move at most this many files in this run}
        {--chunk=200 : How many rows are read at a time}';

    protected $description = 'Move the files attached before the private disk existed from the public disk to the private one';

    /** @var array<string, true> the paths the emails of the matters list (kept public for them) */
    private array $emailPaths = [];

    public function handle(): int
    {
        $disk = (string) config('attachments.disk');

        if ($disk === 'public') {
            $this->error('The attachments disk is the public one: nowhere to move to. Set ATTACHMENTS_DISK.');

            return self::FAILURE;
        }

        $dry = (bool) $this->option('dry-run');
        $limit = $this->option('limit') !== null ? max(0, (int) $this->option('limit')) : null;
        $chunk = max(1, (int) $this->option('chunk'));
        $public = Storage::disk('public');
        $private = Storage::disk($disk);

        $this->emailPaths = MatterEmail::keptPaths();
        $stats = ['moved' => 0, 'bytes' => 0, 'missing' => 0, 'email' => 0, 'failed' => 0, 'left' => 0];
        $failures = [];

        $this->info($dry ? 'Dry run: nothing is changed.' : "Moving attachments from the public disk to \"{$disk}\".");

        DB::table('attachments')->where('disk', 'public')->orderBy('id')->chunkById($chunk, function ($rows) use (&$stats, &$failures, $limit, $dry, $disk, $public, $private): bool {
            foreach ($rows as $row) {
                if ($limit !== null && $stats['moved'] >= $limit) {
                    return false;
                }

                $path = (string) $row->path;

                if ($path === '' || ! $public->exists($path)) {
                    $stats['missing']++;

                    continue;
                }

                if (isset($this->emailPaths[$path])) {
                    $stats['email']++;

                    continue;
                }

                if ($dry) {
                    $stats['moved']++;
                    $stats['bytes'] += (int) ($public->size($path) ?: 0);

                    continue;
                }

                try {
                    $stats['bytes'] += $this->move($row, $path, $disk, $public, $private, $stats);
                    $stats['moved']++;
                } catch (Throwable $e) {
                    $stats['failed']++;
                    $failures[] = '#'.$row->id.' '.$path.': '.$e->getMessage();
                    Log::warning('Attachment not moved to the private disk', ['attachment' => $row->id, 'path' => $path, 'error' => $e->getMessage()]);
                }
            }

            return true;
        });

        foreach ($failures as $failure) {
            $this->error($failure);
        }

        $this->table(
            [$dry ? 'Would be moved' : 'Moved', 'Size', 'File missing (left as it is)', 'Kept public for an email', 'Failed', 'Public copy not deleted'],
            [[$stats['moved'], $this->size($stats['bytes']), $stats['missing'], $stats['email'], $stats['failed'], $stats['left']]],
        );

        if ($stats['failed'] > 0) {
            $this->error('Some files were not moved: they stay where they were. Run it again once the cause is cleared.');
        }

        return $stats['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * One file: copied, checked, its row pointed at the copy, the public one deleted.
     *
     * @param  array<string, int>  $stats  'left' counts a public copy that could not be deleted
     * @return int its size in bytes
     */
    private function move(object $row, string $path, string $disk, Filesystem $public, Filesystem $private, array &$stats): int
    {
        $target = $this->targetPath($row, $path);
        ['size' => $size, 'checksum' => $checksum] = FileCopy::copy($public, $path, $private, $target, $row->checksum);
        $mime = $row->mime ?: ($public->mimeType($path) ?: null);

        try {
            // Pointed at the copy — not through the model: nothing about the file's contents changed, so no
            // activity entry and no new "updated" time.
            $updated = DB::table('attachments')->where('id', $row->id)->where('disk', 'public')->update([
                'disk' => $disk,
                'path' => $target,
                'legacy_path' => $path,
                'checksum' => $row->checksum ?: $checksum,
                'size_bytes' => $row->size_bytes ?: $size,
                'mime' => $mime,
            ]);
            throw_if($updated === 0, \RuntimeException::class, 'the row changed meanwhile');
        } catch (Throwable $e) {
            // The original was never touched; the failed copy goes.
            $private->delete($target);

            throw $e;
        }

        // The original goes — unless another row still uses the same public file.
        $shared = DB::table('attachments')->where('disk', 'public')->where('path', $path)->exists();
        if (! $shared && ! $public->delete($path)) {
            $stats['left']++;
            Log::warning('Attachment moved but its public copy was not deleted', ['attachment' => $row->id, 'path' => $path]);
        }

        return $size;
    }

    /** Named as the attachment service names a new file: <owner>/<year>/<owner id>/<uuid>.<ext>. */
    private function targetPath(object $row, string $path): string
    {
        [$owner, $id] = filled($row->attachable_type) && filled($row->attachable_id)
            ? [Str::snake(class_basename((string) $row->attachable_type)), $row->attachable_id]
            : (filled($row->matter_id) ? ['matter', $row->matter_id] : ['attachment', 0]);

        $extension = strtolower((string) preg_replace('/[^A-Za-z0-9]/', '', $row->extension ?: pathinfo($path, PATHINFO_EXTENSION)));
        $year = $row->created_at ? substr((string) $row->created_at, 0, 4) : now()->format('Y');

        return $owner.'/'.$year.'/'.$id.'/'.Str::uuid().($extension !== '' ? '.'.$extension : '');
    }

    private function size(int $bytes): string
    {
        return number_format($bytes / (1024 * 1024), 1).' MB';
    }
}
