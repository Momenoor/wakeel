<?php

namespace App\Services\Backup;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * The database written out as SQL (gzipped) by the app itself — shared hosting runs no mysqldump — a table at a
 * time: each table dropped and made again, then its rows in batches. In steps of a few seconds (a request may run
 * no longer than a minute there), each going on from where the last stopped. What only lasts a moment (sessions,
 * cache, queued jobs) keeps its table, not its rows.
 */
class DatabaseBackup
{
    public const STATE = 'backup';

    /** How long one step may run: well inside a request's minute. */
    private const STEP_SECONDS = 20;

    /** Rows a statement inserts. */
    private const BATCH = 500;

    /** Tables made again empty: their rows are of the moment only. */
    private const NO_ROWS = ['sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs'];

    public function __construct(private readonly BackupStore $store) {}

    /** @return array<string, mixed>|null */
    public function state(): ?array
    {
        return $this->store->state(self::STATE);
    }

    public function running(): bool
    {
        return ($this->state()['done'] ?? true) === false;
    }

    /** A backup begun: its file opened with what every restore of it first needs; each table's rows counted. */
    public function start(): void
    {
        $tables = self::tables();
        $name = $this->store->newName();
        // Counted before the file is begun: a failure here leaves no half-made backup behind.
        $total = array_sum(array_map(fn (string $table): int => in_array($table, self::NO_ROWS, true) ? 0 : DB::table($table)->count(), $tables));

        $this->write($name, $this->sqlite()
            ? "PRAGMA foreign_keys=OFF;\n"
            : "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n");

        $this->store->saveState(self::STATE, [
            'name' => $name,
            'tables' => $tables,
            'table' => 0,
            // The last key written of the table (or how many rows, one without a single whole-number key).
            'after' => null,
            'created' => false,
            'rows_done' => 0,
            'rows_total' => $total,
            'done' => false,
            'error' => null,
        ]);
    }

    /**
     * As much of it as fits in a step. Its state after — null when another request is running a step already.
     *
     * @return array<string, mixed>|null
     */
    public function step(): ?array
    {
        return $this->store->exclusively(self::STATE, function (): array {
            $state = $this->state() ?? throw new RuntimeException('No backup running.');
            $until = microtime(true) + self::STEP_SECONDS;

            try {
                while (! $state['done'] && microtime(true) < $until) {
                    $state = $this->next($state);
                    $this->store->saveState(self::STATE, $state);
                }
            } catch (\Throwable $e) {
                $state['error'] = $e->getMessage();
                $state['done'] = true;
                $this->store->saveState(self::STATE, $state);
                @unlink($this->store->path($state['name']));
            }

            if ($state['done'] && $state['error'] === null) {
                $this->store->prune();
            }

            return $state;
        });
    }

    /**
     * The next piece: the table's structure, or its next rows, or — every table done — the end.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function next(array $state): array
    {
        $table = $state['tables'][$state['table']] ?? null;

        if ($table === null) {
            $this->write($state['name'], $this->sqlite() ? "PRAGMA foreign_keys=ON;\n" : "SET FOREIGN_KEY_CHECKS=1;\n");

            return [...$state, 'done' => true];
        }

        if (! $state['created']) {
            $this->write($state['name'], 'DROP TABLE IF EXISTS '.$this->quoted($table).";\n".implode(";\n", $this->createSql($table)).";\n");

            return [...$state, 'created' => true, 'after' => null];
        }

        $rows = in_array($table, self::NO_ROWS, true) ? [] : $this->rows($table, $state['after']);

        if ($rows === []) {
            return [...$state, 'table' => $state['table'] + 1, 'created' => false, 'after' => null];
        }

        $this->write($state['name'], $this->insertSql($table, $rows));
        $key = $this->key($table);

        return [
            ...$state,
            'after' => $key !== null ? end($rows)->{$key} : (int) $state['after'] + count($rows),
            'rows_done' => $state['rows_done'] + count($rows),
        ];
    }

    /**
     * The table's next rows: after the last key written (or, without a single whole-number key, the next by place).
     *
     * @return list<object>
     */
    private function rows(string $table, mixed $after): array
    {
        $key = $this->key($table);
        $query = DB::table($table)->limit(self::BATCH);

        return $key !== null
            ? $query->when($after !== null, fn ($q) => $q->where($key, '>', $after))->orderBy($key)->get()->all()
            : $query->offset((int) $after)->get()->all();
    }

    /** One INSERT for the rows, on one line: each value quoted, binary ones as hex. */
    private function insertSql(string $table, array $rows): string
    {
        $binary = $this->binaryColumns($table);
        $columns = array_keys((array) $rows[0]);
        $pdo = DB::connection()->getPdo();

        $values = array_map(fn (object $row): string => '('.implode(',', array_map(
            fn (string $column): string => match (true) {
                $row->{$column} === null => 'NULL',
                in_array($column, $binary, true) => "X'".bin2hex((string) $row->{$column})."'",
                default => $pdo->quote((string) $row->{$column}),
            },
            $columns,
        )).')', $rows);

        return 'INSERT INTO '.$this->quoted($table).' ('.implode(',', array_map($this->quoted(...), $columns)).') VALUES '.implode(',', $values).";\n";
    }

    /** @return list<string> the table, and (SQLite) its indexes, as made */
    private function createSql(string $table): array
    {
        if ($this->sqlite()) {
            return DB::table('sqlite_master')->where('tbl_name', $table)->whereIn('type', ['table', 'index'])->whereNotNull('sql')
                ->orderByRaw("type = 'index'")->pluck('sql')->all();
        }

        return [(string) ((array) DB::selectOne('SHOW CREATE TABLE '.$this->quoted($table)))['Create Table']];
    }

    /**
     * This database's own tables — only its: unnamed, MySQL lists every database's the user can see (the other
     * sites' on a shared host among them). The restore drops these and no others.
     *
     * @return list<string>
     */
    public static function tables(): array
    {
        $schema = DB::connection()->getDriverName() === 'sqlite' ? null : DB::connection()->getDatabaseName();

        return array_values(array_filter(Schema::getTableListing($schema, schemaQualified: false), fn (string $table): bool => ! str_starts_with($table, 'sqlite_')));
    }

    /** The table's own single whole-number key (id), by which its rows are paged — null when it has none. */
    private function key(string $table): ?string
    {
        static $keys = [];

        return $keys[$table] ??= (function () use ($table): string|false {
            $primary = collect(Schema::getIndexes($table))->first(fn (array $index): bool => $index['primary']);
            $column = count($primary['columns'] ?? []) === 1 ? $primary['columns'][0] : null;
            $type = collect(Schema::getColumns($table))->firstWhere('name', $column)['type_name'] ?? '';

            return $column !== null && str_contains(strtolower((string) $type), 'int') ? $column : false;
        })() ?: null;
    }

    /** @return list<string> */
    private function binaryColumns(string $table): array
    {
        static $binary = [];

        return $binary[$table] ??= collect(Schema::getColumns($table))
            ->filter(fn (array $column): bool => (bool) preg_match('/blob|binary/i', (string) $column['type_name']))
            ->pluck('name')->all();
    }

    private function quoted(string $name): string
    {
        return '`'.str_replace('`', '``', $name).'`';
    }

    private function sqlite(): bool
    {
        return DB::connection()->getDriverName() === 'sqlite';
    }

    private function write(string $name, string $sql): void
    {
        $file = gzopen($this->store->path($name), 'ab6') ?: throw new RuntimeException('The backup file cannot be written.');
        gzwrite($file, $sql);
        gzclose($file);
    }
}
