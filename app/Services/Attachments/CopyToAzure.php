<?php

namespace App\Services\Attachments;

use App\Support\Integrations;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * The files kept on this server taken to Azure Blob Storage (Settings → Integrations): the attached files and
 * the signatures and stamps, each under the same name. Each one is copied, checked against the original
 * (FileCopy), and only then is its row pointed at Azure; the original is kept unless asked to delete it.
 *
 * Files still on the public disk are left: `attachments:move` takes them to the private one first (an email
 * may read one there). Safe to stop and run again — only rows not yet in Azure are looked at.
 */
class CopyToAzure
{
    /** The tables whose rows each keep a file, and what is read of them (an attachment's recorded checksum too). */
    private const TABLES = ['attachments' => ['id', 'disk', 'path', 'checksum'], 'signature_stamps' => ['id', 'disk', 'path']];

    /** How many files are still to copy. */
    public static function remaining(): int
    {
        return collect(self::TABLES)->keys()->sum(fn (string $table): int => self::pending($table)->count());
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
        $lock = Cache::lock('copy-to-azure', 3600);

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

        foreach (self::TABLES as $table => $columns) {
            self::pending($table)->select($columns)->chunkById(100, function ($rows) use (&$stats, $enough, $deleteLocal, $azure, $table): bool {
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
                        $stats['failed']++;
                        $stats['errors'][] = $table.' #'.$row->id.': '.$e->getMessage();
                        Log::warning('File not copied to Azure', ['table' => $table, 'id' => $row->id, 'path' => $row->path, 'error' => $e->getMessage()]);

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
                break;
            }
        }

        return $stats;
    }

    /** Its rows with a file kept on this server (not those on the public disk — an attachment with none is there). */
    private static function pending(string $table): Builder
    {
        return DB::table($table)
            ->whereNotNull('disk')
            ->whereNotIn('disk', ['public', Integrations::AZURE_DISK])
            ->whereNotNull('path')
            ->where('path', '!=', '');
    }
}
