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
    /** Where the attached files and the signatures and stamps were kept on this server (config/filesystems.php). */
    private const LOCAL = 'attachments';

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
        return $this->locked($seconds, fn (?float $until): array => $this->copy($limit, $deleteLocal, $until));
    }

    /**
     * The copies left on this server of the files now in Azure (the attached files and the signatures and
     * stamps, copied with their originals kept) removed — each only once its copy in Azure is checked to be
     * it. Null while a copy runs. Stops after $seconds.
     *
     * @return array{removed: int, bytes: int, failed: int, errors: list<string>}|null
     */
    public function removeLocal(?int $seconds = null): ?array
    {
        return $this->locked($seconds, function (?float $until): array {
            $here = Storage::disk(self::LOCAL);
            $azure = Storage::disk(Integrations::AZURE_DISK);
            $stats = ['removed' => 0, 'bytes' => 0, 'failed' => 0, 'errors' => []];

            foreach (self::leftHere() as $path) {
                if ($until !== null && microtime(true) >= $until) {
                    break;
                }

                try {
                    $size = (int) $here->size($path);
                    FileCopy::assertSame($here, $path, $azure, $path);
                    $here->delete($path);
                    $stats['removed']++;
                    $stats['bytes'] += $size;
                } catch (Throwable $e) {
                    $stats['failed']++;
                    $stats['errors'][] = $path.': '.$e->getMessage();
                    Log::warning('Copy on this server not removed: its copy in Azure is not it', ['path' => $path, 'error' => $e->getMessage()]);
                }
            }

            return $stats;
        });
    }

    /**
     * The files in Azure whose copy is still on this server.
     *
     * @return list<string>
     */
    public static function leftHere(): array
    {
        $here = Storage::disk(self::LOCAL);
        $paths = [];

        foreach (array_keys(self::TABLES) as $table) {
            DB::table($table)->where('disk', Integrations::AZURE_DISK)->whereNotNull('path')->where('path', '!=', '')
                ->select(['id', 'path'])->orderBy('id')
                ->each(function ($row) use (&$paths, $here): void {
                    if (! isset($paths[$row->path]) && $here->exists($row->path)) {
                        $paths[$row->path] = true;
                    }
                });
        }

        return array_keys($paths);
    }

    /** Whether any file is kept in Azure (then Azure cannot be turned off: they would no longer open). */
    public static function anyInAzure(): bool
    {
        $azure = [Integrations::AZURE_DISK, Integrations::AZURE_PUBLIC_DISK];

        // The emails' files of no row (unfiled ones) by what is under public/ there; Azure unreachable: taken to hold some.
        return once(fn (): bool => DB::table('attachments')->whereIn('disk', $azure)->exists()
            || DB::table('signature_stamps')->whereIn('disk', $azure)->exists()
            || (Integrations::azureOn() && rescue(fn (): bool => Storage::disk(Integrations::AZURE_PUBLIC_DISK)->directories() !== [], true, report: false)));
    }

    /**
     * Where the files are, by kind: in Azure, and on this server.
     *
     * @return array<string, array{azure: int, here: int}>
     */
    public static function counts(): array
    {
        $azure = [Integrations::AZURE_DISK, Integrations::AZURE_PUBLIC_DISK];

        return collect(['attachments' => __('Attached files (with emails\' and minutes\' files)'), 'signature_stamps' => __('Signatures and stamps')])
            ->mapWithKeys(function (string $label, string $table) use ($azure): array {
                $count = DB::table($table)->whereNotNull('path')->where('path', '!=', '')
                    ->selectRaw('sum(case when disk in (?, ?) then 1 else 0 end) as azure, sum(case when disk in (?, ?) then 0 else 1 end) as here', [...$azure, ...$azure])
                    ->first();

                return [$label => ['azure' => (int) ($count->azure ?? 0), 'here' => (int) ($count->here ?? 0)]];
            })
            ->all();
    }

    /**
     * One at a time — copying, or removing the copies left here: null while another runs. The lock is held
     * no longer than the run can take, so one stopped short (a worker past its time) does not block the next
     * for long.
     *
     * @template T
     *
     * @param  callable(?float): T  $work  given when to stop
     * @return T|null
     */
    private function locked(?int $seconds, callable $work): mixed
    {
        $lock = Cache::lock('copy-to-azure', $seconds !== null ? $seconds + 120 : 6 * 3600);

        if (! $lock->get()) {
            return null;
        }

        try {
            return $work($seconds !== null ? microtime(true) + $seconds : null);
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
