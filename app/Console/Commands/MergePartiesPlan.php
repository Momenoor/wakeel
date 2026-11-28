<?php

namespace App\Console\Commands;

use App\Models\Party;
use App\Services\MMS\PartyMerger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use Throwable;

/**
 * Duplicates merged from a reviewed list: an Excel workbook or a CSV with a "group", an "action" ("keep" the one kept, "merge" each merged
 * into it, anything else left alone) and the party's "id" on each row (more columns are read past). Without --apply
 * it says what it would do; with it, each group is merged (PartyMerger) and every reference to a party is checked
 * afterwards (dangling()): none may point at a party no longer there.
 */
class MergePartiesPlan extends Command
{
    protected $signature = 'parties:merge-plan {file : The reviewed list: an Excel workbook (.xlsx) or a CSV} {--apply : Merge them; otherwise only say what would be done}';

    protected $description = 'Merge duplicate parties as a reviewed CSV says (group, action keep/merge, id), then check that nothing points at a party that is gone';

    public function handle(PartyMerger $merger): int
    {
        $groups = self::groups((string) $this->argument('file'));

        if ($groups === null) {
            $this->error('That file is not a merge list: it needs the columns group, action and id.');

            return self::FAILURE;
        }

        $merged = 0;
        $skipped = 0;

        foreach ($groups as $group => ['keep' => $keep, 'merge' => $merge, 'name' => $name]) {
            $into = count($keep) === 1 ? Party::query()->find($keep[0]) : null;
            $from = Party::query()->findMany($merge)->reject(fn (Party $party): bool => $into !== null && $party->is($into));

            if ($into === null || $from->isEmpty()) {
                $this->warn("Group {$group}: left alone (it needs one party to keep, there still, and others to merge into it).");
                $skipped++;

                continue;
            }

            $this->line("Group {$group}: #{$into->getKey()} {$into->name} ← ".$from->map(fn (Party $p): string => "#{$p->getKey()} {$p->name}")->implode(', ')
                .' → '.($name ?: PartyMerger::fullest([$into->name, ...$from->pluck('name')->all()])));

            if (! $this->option('apply')) {
                continue;
            }

            try {
                $merger->merge($into, $from);
                $merged += $from->count();

                // The name the list gives it, when it gives one; else the fullest of theirs (PartyMerger).
                if (filled($name)) {
                    $into->refresh()->update(['name' => $name]);
                }
            } catch (Throwable $e) {
                $this->error("Group {$group}: not merged — {$e->getMessage()}");
                $skipped++;
            }
        }

        if (! $this->option('apply')) {
            $this->info(count($groups).' groups read. Nothing changed: run again with --apply to merge them.');

            return self::SUCCESS;
        }

        $this->info("{$merged} parties merged, {$skipped} groups left alone.");

        $dangling = self::dangling();

        foreach ($dangling as $where => $count) {
            $this->error(sprintf('%6d  %s point at a party that is not there', $count, $where));
        }

        if ($dangling === []) {
            $this->info('Checked: every reference to a party points at one that is there.');
        }

        return $dangling === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * The groups of the list: in each, the ids kept and the ids merged.
     *
     * @return array<string, array{keep: list<int>, merge: list<int>, name: ?string}>|null name: the kept party's "final_name", when the list has the column
     */
    public static function groups(string $path): ?array
    {
        $rows = self::rows($path);
        $header = $rows === null ? [] : array_map(fn ($h): string => mb_strtolower(trim((string) preg_replace('/^\x{FEFF}/u', '', (string) $h))), (array) array_shift($rows));
        $at = array_flip($header);

        if (! isset($at['group'], $at['action'], $at['id'])) {
            return null;
        }

        $groups = [];

        foreach ($rows as $row) {
            $group = trim((string) ($row[$at['group']] ?? ''));
            $action = mb_strtolower(trim((string) ($row[$at['action']] ?? '')));
            $id = (int) ($row[$at['id']] ?? 0);

            if ($group === '' || $id <= 0 || ! in_array($action, ['keep', 'merge'], true)) {
                continue;
            }

            $groups[$group] ??= ['keep' => [], 'merge' => [], 'name' => null];
            $groups[$group][$action][] = $id;

            if ($action === 'keep' && isset($at['final_name']) && filled($final = trim((string) ($row[$at['final_name']] ?? '')))) {
                $groups[$group]['name'] = $final;
            }
        }

        return $groups;
    }

    /**
     * The list's rows, the header first: from an Excel workbook (its first sheet) or a CSV.
     *
     * @return list<list<mixed>>|null
     */
    private static function rows(string $path): ?array
    {
        if (! is_file($path)) {
            return null;
        }

        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'xlsx') {
            $reader = new XlsxReader;
            $reader->open($path);
            $rows = [];

            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $rows[] = $row->toArray();
                }

                break;
            }

            $reader->close();

            return $rows;
        }

        $handle = fopen($path, 'r');
        $rows = [];

        while (($row = fgetcsv($handle, escape: '')) !== false) {
            $rows[] = $row;
        }

        fclose($handle);

        return $rows;
    }

    /**
     * How many rows of each table (and the minutes' attendees, and the parties' own parent_id) point at a party
     * that is not there.
     *
     * @return array<string, int>
     */
    public static function dangling(): array
    {
        $found = [];
        $missing = fn (string $table, string $column): int => DB::table($table)
            ->whereNotNull($column)
            ->whereNotIn($column, DB::table('parties')->select('id'))
            ->count();

        foreach ([...PartyMerger::references(), ['matter_party', 'party_id'], ['parties', 'parent_id']] as [$table, $column]) {
            if ($table === 'parties' && $column === 'id') {
                continue;
            }

            $found["{$table}.{$column}"] = $missing($table, $column);
        }

        $ids = array_flip(DB::table('parties')->pluck('id')->map(fn ($id): int => (int) $id)->all());
        $found['matter_minutes.attendees'] = DB::table('matter_minutes')->whereNotNull('attendees')->pluck('attendees')
            ->sum(fn ($json): int => collect((array) json_decode((string) $json, true))
                ->filter(fn ($a): bool => is_array($a) && collect(['party_id', 'represents'])->contains(fn (string $k): bool => filled($a[$k] ?? null) && ! isset($ids[(int) $a[$k]])))
                ->count());

        return array_filter($found);
    }
}
