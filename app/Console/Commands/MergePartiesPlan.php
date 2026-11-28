<?php

namespace App\Console\Commands;

use App\Models\Party;
use App\Services\MMS\PartyMerger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Duplicates merged from a reviewed list: a CSV with a "group", an "action" ("keep" the one kept, "merge" each merged
 * into it, anything else left alone) and the party's "id" on each row (more columns are read past). Without --apply
 * it says what it would do; with it, each group is merged (PartyMerger) and every reference to a party is checked
 * afterwards (dangling()): none may point at a party no longer there.
 */
class MergePartiesPlan extends Command
{
    protected $signature = 'parties:merge-plan {file : The reviewed CSV} {--apply : Merge them; otherwise only say what would be done}';

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

        foreach ($groups as $group => ['keep' => $keep, 'merge' => $merge]) {
            $into = count($keep) === 1 ? Party::query()->find($keep[0]) : null;
            $from = Party::query()->findMany($merge)->reject(fn (Party $party): bool => $into !== null && $party->is($into));

            if ($into === null || $from->isEmpty()) {
                $this->warn("Group {$group}: left alone (it needs one party to keep, there still, and others to merge into it).");
                $skipped++;

                continue;
            }

            $this->line("Group {$group}: #{$into->getKey()} {$into->name} ← ".$from->map(fn (Party $p): string => "#{$p->getKey()} {$p->name}")->implode(', '));

            if (! $this->option('apply')) {
                continue;
            }

            try {
                $merger->merge($into, $from);
                $merged += $from->count();
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
     * @return array<string, array{keep: list<int>, merge: list<int>}>|null
     */
    public static function groups(string $path): ?array
    {
        $handle = @fopen($path, 'r');

        if ($handle === false) {
            return null;
        }

        $header = fgetcsv($handle, escape: '');
        $header = array_map(fn ($h): string => mb_strtolower(trim((string) preg_replace('/^\x{FEFF}/u', '', (string) $h))), (array) $header);
        $at = array_flip($header);

        if (! isset($at['group'], $at['action'], $at['id'])) {
            fclose($handle);

            return null;
        }

        $groups = [];

        while (($row = fgetcsv($handle, escape: '')) !== false) {
            $group = trim((string) ($row[$at['group']] ?? ''));
            $action = mb_strtolower(trim((string) ($row[$at['action']] ?? '')));
            $id = (int) ($row[$at['id']] ?? 0);

            if ($group === '' || $id <= 0 || ! in_array($action, ['keep', 'merge'], true)) {
                continue;
            }

            $groups[$group] ??= ['keep' => [], 'merge' => []];
            $groups[$group][$action][] = $id;
        }

        fclose($handle);

        return $groups;
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
