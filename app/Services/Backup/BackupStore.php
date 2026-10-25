<?php

namespace App\Services\Backup;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * Where the database's backups are kept: a private folder (storage/app/private/backups), never served by a link —
 * downloaded only through the app, by a super admin. The last few are kept; and what a backup or a restore in
 * progress has reached, in a small file beside them — readable while the database itself is being replaced.
 */
class BackupStore
{
    public const DIRECTORY = 'backups';

    /** How many backups are kept: older ones go when a new one is made. */
    public const KEEP = 5;

    /** A backup's name: made by the app, or uploaded or copied in (cPanel) as a .sql or .sql.gz. */
    public const NAME = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,150}\.sql(\.gz)?$/';

    /** The made ones: wakeel-20261025-143000.sql.gz — the only ones pruned. */
    private const MADE = '/^wakeel-\d{8}-\d{6}\.sql\.gz$/';

    /** Who may back the database up, download it and restore it: a super admin only. */
    public static function mayUse(): bool
    {
        return auth()->user()?->hasRole('super-admin') ?? false;
    }

    public function path(string $name = ''): string
    {
        $disk = Storage::disk('local');
        $disk->makeDirectory(self::DIRECTORY);

        return $disk->path(self::DIRECTORY.($name !== '' ? '/'.$name : ''));
    }

    public static function isBackupName(string $name): bool
    {
        return preg_match(self::NAME, $name) === 1;
    }

    public function newName(): string
    {
        return 'wakeel-'.now()->format('Ymd-His').'.sql.gz';
    }

    /**
     * Each backup there — its name, size and when it was made — newest first; one being written left out.
     *
     * @return Collection<int, array{name: string, size: int, at: int}>
     */
    public function all(?string $writing = null): Collection
    {
        return collect(glob($this->path().'/*') ?: [])
            ->filter(fn (string $file): bool => is_file($file) && self::isBackupName(basename($file)) && basename($file) !== $writing)
            ->map(fn (string $file): array => ['name' => basename($file), 'size' => (int) filesize($file), 'at' => (int) filemtime($file)])
            ->sortByDesc('at')
            ->values();
    }

    /** The made backups beyond the last few deleted (uploaded ones are left to whoever put them there). */
    public function prune(): void
    {
        $this->all()->filter(fn (array $backup): bool => preg_match(self::MADE, $backup['name']) === 1)
            ->slice(self::KEEP)
            ->each(fn (array $backup) => @unlink($this->path($backup['name'])));
    }

    public function delete(string $name): void
    {
        if (self::isBackupName($name)) {
            @unlink($this->path($name));
        }
    }

    /** @return array<string, mixed>|null */
    public function state(string $which): ?array
    {
        $file = $this->path('.'.$which.'.json');

        return is_file($file) ? (json_decode((string) file_get_contents($file), true) ?: null) : null;
    }

    /** @param  array<string, mixed>|null  $state */
    public function saveState(string $which, ?array $state): void
    {
        $file = $this->path('.'.$which.'.json');

        $state === null ? @unlink($file) : file_put_contents($file, json_encode($state), LOCK_EX);
    }

    /**
     * $work run by one request at a time for this backup or restore — another that arrives meanwhile is told so
     * (null) rather than running the same step twice.
     *
     * @template T
     *
     * @param  callable(): T  $work
     * @return T|null
     */
    public function exclusively(string $which, callable $work): mixed
    {
        $lock = fopen($this->path('.'.$which.'.lock'), 'c');

        if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
            return null;
        }

        try {
            return $work();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
