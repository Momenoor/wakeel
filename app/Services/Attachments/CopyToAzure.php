<?php

namespace App\Services\Attachments;

use App\Models\MatterEmail;
use App\Support\Documents;
use App\Support\Integrations;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * The files kept on this server taken to Azure Blob Storage (Settings → Integrations), each under the same
 * name, each copied and checked against its original (FileCopy) before it is read from Azure:
 *  - the attached files and the signatures and stamps — their rows pointed at Azure; the originals kept
 *    unless asked to delete them;
 *  - the system's documents on the public disk (App\Support\Documents: emails' PDFs and files, minutes and
 *    their signed copies) — moved: the original removed once its copy is checked, as each is read from here
 *    while it is here; their attachment rows pointed at Azure.
 *
 * Safe to stop and run again — only what is not yet in Azure is looked at.
 */
class CopyToAzure
{
    /** The tables whose rows each keep a file, and what is read of them (an attachment's recorded checksum too). */
    private const TABLES = ['attachments' => ['id', 'disk', 'path', 'checksum'], 'signature_stamps' => ['id', 'disk', 'path']];

    /** How many files are still to copy. */
    public static function remaining(): int
    {
        return collect(self::TABLES)->keys()->sum(fn (string $table): int => self::pending($table)->count()) + count(self::documents());
    }

    /**
     * One copy at a time (a second, started meanwhile, would wait on the rows the first is pointing at
     * Azure): null while another runs. Each row is pointed at Azure on its own, at once — never one long
     * transaction around the copying. Stops after $limit files or $seconds, whichever comes first.
     *
     * @return array{copied: int, bytes: int, missing: int, failed: int, errors: list<string>}|null
     */
    public function run(?int $limit = null, bool $deleteLocal = false, ?int $seconds = null): ?array
    {
        // Held no longer than the run can take: one stopped short (a worker past its time) does not block the next for long.
        $lock = Cache::lock('copy-to-azure', $seconds !== null ? $seconds + 120 : 6 * 3600);

        if (! $lock->get()) {
            return null;
        }

        try {
            return $this->copy($limit, $deleteLocal, $seconds !== null ? microtime(true) + $seconds : null);
        } finally {
            $lock->release();
        }
    }

    /**
     * @return array{copied: int, bytes: int, missing: int, failed: int, errors: list<string>}
     */
    private function copy(?int $limit, bool $deleteLocal, ?float $until): array
    {
        $azure = Storage::disk(Integrations::AZURE_DISK);
        $stats = ['copied' => 0, 'bytes' => 0, 'missing' => 0, 'failed' => 0, 'errors' => []];
        $enough = fn (): bool => ($limit !== null && $stats['copied'] >= $limit) || ($until !== null && microtime(true) >= $until);
        $failed = function (string $what, string $path, Throwable $e) use (&$stats): void {
            $stats['failed']++;
            $stats['errors'][] = $what.': '.$e->getMessage();
            Log::warning('File not copied to Azure', ['file' => $what, 'path' => $path, 'error' => $e->getMessage()]);
        };

        foreach (self::TABLES as $table => $columns) {
            self::pending($table)->select($columns)->chunkById(100, function ($rows) use (&$stats, $enough, $failed, $deleteLocal, $azure, $table): bool {
                foreach ($rows as $row) {
                    if ($enough()) {
                        return false;
                    }

                    $disk = (string) $row->disk;
                    $local = Storage::disk($disk);

                    if (! $local->exists($row->path)) {
                        $stats['missing']++;

                        continue;
                    }

                    try {
                        $stats['bytes'] += FileCopy::copy($local, $row->path, $azure, $row->path, $row->checksum ?? null)['size'];
                        // Not through the model: the file is the same, so no activity entry and no new "updated" time.
                        DB::table($table)->where('id', $row->id)->update(['disk' => Integrations::AZURE_DISK]);
                        $stats['copied']++;
                    } catch (Throwable $e) {
                        $failed($table.' #'.$row->id, $row->path, $e);

                        continue;
                    }

                    // The original goes when asked — unless another row still uses it.
                    if ($deleteLocal && ! DB::table($table)->where('path', $row->path)->where('disk', $disk)->exists()) {
                        $local->delete($row->path);
                    }
                }

                return true;
            });

            if ($enough()) {
                return $stats;
            }
        }

        $local = Storage::disk(Documents::LOCAL);
        $azure = Storage::disk(Integrations::AZURE_PUBLIC_DISK);

        foreach (self::documents() as $path => $checksum) {
            if ($enough()) {
                break;
            }

            try {
                $stats['bytes'] += FileCopy::copy($local, $path, $azure, $path, $checksum)['size'];
                DB::table('attachments')->where('path', $path)->where(fn (Builder $query) => $query->where('disk', Documents::LOCAL)->orWhereNull('disk'))
                    ->update(['disk' => Integrations::AZURE_PUBLIC_DISK]);
                $local->delete($path);
                $stats['copied']++;
            } catch (Throwable $e) {
                $failed($path, $path, $e);
            }
        }

        return $stats;
    }

    /**
     * The system's documents still on this server: its attachments' files on the public disk and those the
     * emails list (an unfiled email's), each with its recorded checksum when it has one.
     *
     * @return array<string, ?string>
     */
    private static function documents(): array
    {
        $paths = array_map(fn (): ?string => null, MatterEmail::keptPaths());

        DB::table('attachments')->where(fn (Builder $query) => $query->where('disk', Documents::LOCAL)->orWhereNull('disk'))
            ->whereNotNull('path')->where('path', '!=', '')
            ->select(['id', 'path', 'checksum'])->orderBy('id')
            ->each(function ($row) use (&$paths): void {
                $paths[(string) $row->path] = $row->checksum ?: ($paths[(string) $row->path] ?? null);
            });

        $local = Storage::disk(Documents::LOCAL);

        return array_filter($paths, fn (string $path): bool => $local->exists($path), ARRAY_FILTER_USE_KEY);
    }

    /** Its rows with a file kept on this server, but on the public disk (the documents', above). */
    private static function pending(string $table): Builder
    {
        return DB::table($table)
            ->whereNotNull('disk')
            ->whereNotIn('disk', [Documents::LOCAL, Integrations::AZURE_DISK, Integrations::AZURE_PUBLIC_DISK])
            ->whereNotNull('path')
            ->where('path', '!=', '');
    }
}
