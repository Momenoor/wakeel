<?php

namespace App\Services\Backup;

use App\Models\Setting;
use App\Services\Updater\NativeMaintenance;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * The whole database replaced by a backup: the site taken offline, every table dropped, the backup's statements
 * run a step at a time (a request may run no longer than a minute on shared hosting), what this version adds to
 * an older backup's database made (migrate), and the site back online. Nothing here can rely on the database —
 * not even the sessions: its state is kept in a file (BackupStore), and its page is reached with a key of its
 * own, not a login (SystemRestoreController).
 */
class DatabaseRestore
{
    public const STATE = 'restore';

    private const STEP_SECONDS = 20;

    /** MySQL's "already exists" and "duplicate entry": a statement run again after a step was cut off. */
    private const RAN_ALREADY = [1050, 1062, 1061, 1826];

    public function __construct(private readonly BackupStore $store, private readonly NativeMaintenance $maintenance) {}

    /**
     * The restore begun from a backup in the store: the site offline; the key that opens its page.
     */
    public function prepare(string $name): string
    {
        if (! BackupStore::isBackupName($name) || ! is_file($source = $this->store->path($name))) {
            throw new RuntimeException(__('That backup is not on the server.'));
        }

        $key = Str::random(48);

        $this->store->saveState(self::STATE, [
            'key' => hash('sha256', $key),
            'source' => $name,
            'phase' => str_ends_with($name, '.gz') ? 'unpack' : 'drop',
            'sql' => str_ends_with($name, '.gz') ? '.restore.sql' : $name,
            'offset' => 0,
            'size' => str_ends_with($name, '.gz') ? 0 : (int) filesize($source),
            'statements' => 0,
            // A step importing: still so when the next begins, it was cut off.
            'in_step' => false,
            'error' => null,
            'started_at' => now()->toIso8601String(),
        ]);

        $this->maintenance->down();

        return $key;
    }

    /** @return array<string, mixed>|null the restore the key opens, if it is the one in progress */
    public function stateFor(string $key): ?array
    {
        $state = $this->store->state(self::STATE);

        return $state !== null && hash_equals((string) $state['key'], hash('sha256', $key)) ? $state : null;
    }

    /**
     * As much of it as fits in a step. Its state after — null when another request is running a step already.
     *
     * @return array<string, mixed>|null
     */
    public function step(string $key): ?array
    {
        return $this->store->exclusively(self::STATE, function () use ($key): array {
            $state = $this->stateFor($key) ?? throw new RuntimeException('No such restore.');

            if ($state['phase'] === 'done') {
                return $state;
            }

            // Tried again after a failure: from where it stopped, the statement that failed first.
            $state['error'] = null;
            $until = microtime(true) + self::STEP_SECONDS;

            try {
                $state = match ($state['phase']) {
                    'unpack' => $this->unpack($state),
                    'drop' => $this->dropAll($state),
                    'import' => $this->import($state, $until),
                    'migrate' => $this->migrate($state),
                    'finish' => $this->finish($state),
                    default => $state,
                };
            } catch (Throwable $e) {
                $state['error'] = Str::limit($e->getMessage(), 1500);
            }

            $this->store->saveState(self::STATE, $state);

            return $state;
        });
    }

    /**
     * From the start again: every table dropped and the backup run anew — after a failure that going on from
     * where it stopped cannot get past.
     */
    public function restart(string $key): void
    {
        $this->store->exclusively(self::STATE, function () use ($key): void {
            $state = $this->stateFor($key) ?? throw new RuntimeException('No such restore.');
            $this->store->saveState(self::STATE, [...$state, 'phase' => $state['phase'] === 'unpack' ? 'unpack' : 'drop', 'offset' => 0, 'statements' => 0, 'error' => null]);
        });
    }

    /** @param  array<string, mixed>  $state */
    private function unpack(array $state): array
    {
        $in = gzopen($this->store->path($state['source']), 'rb') ?: throw new RuntimeException(__('The backup cannot be read.'));
        $out = fopen($this->store->path($state['sql']), 'wb');

        while (! gzeof($in)) {
            $chunk = gzread($in, 1024 * 1024);
            if ($chunk === false) {
                throw new RuntimeException(__('The backup cannot be read.'));
            }
            fwrite($out, $chunk);
        }

        gzclose($in);
        fclose($out);

        return [...$state, 'phase' => 'drop', 'size' => (int) filesize($this->store->path($state['sql']))];
    }

    /** @param  array<string, mixed>  $state */
    private function dropAll(array $state): array
    {
        $this->foreignKeys(false);

        // This database's own tables, and no other's.
        foreach (DatabaseBackup::tables() as $table) {
            DB::statement('DROP TABLE IF EXISTS `'.str_replace('`', '``', $table).'`');
        }

        return [...$state, 'phase' => 'import', 'offset' => 0, 'statements' => 0];
    }

    /** @param  array<string, mixed>  $state */
    private function import(array $state, float $until): array
    {
        // Each step a connection of its own: what the backup set at its start, set again.
        $this->foreignKeys(false);
        $file = fopen($this->store->path($state['sql']), 'rb') ?: throw new RuntimeException(__('The backup cannot be read.'));
        // The last step cut off (killed at its minute): the statement it was on may have run.
        $cutOff = (bool) $state['in_step'];
        $state['in_step'] = true;
        $first = true;

        try {
            foreach (SqlStatements::from($file, (int) $state['offset']) as ['sql' => $sql, 'next' => $next]) {
                try {
                    DB::unprepared($sql);
                } catch (Throwable $e) {
                    // The first statement after a step was cut off may have run already: what it made is there.
                    if (! ($first && $cutOff && self::ranAlready($e))) {
                        throw new RuntimeException($e->getMessage().' — '.Str::limit($sql, 300));
                    }
                }

                $first = false;
                $state = [...$state, 'offset' => $next, 'statements' => $state['statements'] + 1];
                // Saved after each: a step cut off goes on from the statement after the last that ran.
                $this->store->saveState(self::STATE, $state);

                if (microtime(true) >= $until) {
                    return [...$state, 'in_step' => false];
                }
            }
        } catch (Throwable $e) {
            // Stopped by a statement, not cut off: tried again, it is not taken to have run.
            $this->store->saveState(self::STATE, [...$state, 'in_step' => false]);

            throw $e;
        } finally {
            fclose($file);
        }

        return [...$state, 'phase' => 'migrate', 'offset' => $state['size'], 'in_step' => false];
    }

    /**
     * What this version has that an older backup's database lacks.
     *
     * @param  array<string, mixed>  $state
     */
    private function migrate(array $state): array
    {
        $this->foreignKeys(true);

        if (Artisan::call('migrate', ['--force' => true]) !== 0) {
            throw new RuntimeException(trim(Artisan::output()));
        }

        return [...$state, 'phase' => 'finish'];
    }

    /** @param  array<string, mixed>  $state */
    private function finish(array $state): array
    {
        // What was kept of the database before (its settings, among the rest) is no longer what it is.
        Setting::clearCache();
        Cache::flush();

        if ($state['sql'] !== $state['source']) {
            @unlink($this->store->path($state['sql']));
        }

        $this->maintenance->up();

        return [...$state, 'phase' => 'done', 'finished_at' => now()->toIso8601String()];
    }

    private function foreignKeys(bool $on): void
    {
        DB::connection()->getDriverName() === 'sqlite'
            ? DB::statement('PRAGMA foreign_keys = '.($on ? 'ON' : 'OFF'))
            : DB::unprepared($on ? 'SET FOREIGN_KEY_CHECKS=1' : "SET NAMES utf8mb4; SET FOREIGN_KEY_CHECKS=0; SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO'");
    }

    /**
     * Whether a statement failed because what it makes is there already — run before the step was cut off: MySQL's
     * own codes for it; another database's (SQLite, in tests) its "already exists" or a key already taken.
     */
    private static function ranAlready(Throwable $e): bool
    {
        $pdo = $e instanceof \PDOException ? $e : ($e->getPrevious() instanceof \PDOException ? $e->getPrevious() : null);
        $info = $pdo?->errorInfo ?? [];

        return in_array((int) ($info[1] ?? 0), self::RAN_ALREADY, true)
            || (($info[0] ?? null) === '23000' && stripos($e->getMessage(), 'unique') !== false)
            || stripos($e->getMessage(), 'already exists') !== false;
    }
}
