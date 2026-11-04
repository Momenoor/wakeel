<?php

namespace App\Services\MMS;

use App\Models\MatterMinutes;
use App\Models\Party;
use App\Support\RequestMemo;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Two or more parties that are one — the same person or company entered twice — merged into the one kept:
 * everything of the others moves to it (their matters and capacities on them, their representatives, letters,
 * minutes, emails and contacts, creditors, folders, payroll and the rest — every table that points at a party,
 * found from the database itself), their phones and emails are added to its own where not there already, a
 * user account one of them had goes to it when it has none, and they are deleted. Where the kept party was there
 * already (the same matter in the same capacity, a payslip of the same run), the other's row goes, not doubled.
 */
final class PartyMerger
{
    /**
     * @param  iterable<Party|int>  $from  the parties merged into it, by model or id
     * @return array{moved: array<string, int>, removed: int} how many rows each table's column moved, and how many parties went
     */
    public function merge(Party $into, iterable $from): array
    {
        $from = collect($from)
            ->map(fn (Party|int|string $party): Party => $party instanceof Party ? $party : Party::query()->findOrFail((int) $party))
            ->reject(fn (Party $party): bool => $party->is($into))
            ->unique(fn (Party $party): int => (int) $party->getKey())
            ->values();

        if ($from->isEmpty()) {
            throw new RuntimeException(__('Choose the parties to merge into it.'));
        }

        $ids = $from->map(fn (Party $party): int => (int) $party->getKey())->all();
        $intoId = (int) $into->getKey();

        return DB::transaction(function () use ($into, $from, $ids, $intoId): array {
            $moved = [];

            // Their places on the matters first: one the kept party holds already (the same matter, capacity and
            // side) takes their representatives, and theirs goes — never the other way round.
            $moved['matter_party.party_id'] = $this->repointMatterParties($ids, $intoId);

            foreach (self::references() as [$table, $column]) {
                if (($table === 'parties' && $column === 'id') || ($table === 'matter_party' && $column === 'party_id')) {
                    continue;
                }
                $moved["{$table}.{$column}"] = $this->repoint($table, $column, $ids, $intoId);
            }

            // Their history (activity log), and the minutes they attended (kept as JSON).
            $moved['activity_log.subject_id'] = DB::table('activity_log')
                ->where('subject_type', $into->getMorphClass())
                ->whereIn('subject_id', $ids)
                ->update(['subject_id' => $intoId]);
            $moved['matter_minutes.attendees'] = $this->repointAttendees($ids, $intoId);

            // On a matter twice in the same capacity now: once.
            $moved['matter_party (doubled)'] = -$this->dedupeMatterParties($intoId);

            // The kept party: phones and emails combined, a user account it has none of.
            $into->phone = self::combined(Party::values($into->phone), $from->flatMap(fn (Party $party): array => Party::values($party->phone))->all(), fn (string $v): string => (string) preg_replace('/\D+/', '', $v));
            $into->email = self::combined(Party::values($into->email), $from->flatMap(fn (Party $party): array => Party::values($party->email))->all(), 'mb_strtolower');

            if (blank($into->user_id) && ($withUser = $from->first(fn (Party $party): bool => filled($party->user_id))) !== null) {
                $userId = $withUser->user_id;
                $withUser->forceFill(['user_id' => null])->saveQuietly();
                $into->user_id = $userId;
            }

            $into->save();

            foreach ($from as $party) {
                $party->delete();
            }

            RequestMemo::flush();

            return ['moved' => array_filter($moved), 'removed' => $from->count()];
        });
    }

    /**
     * Every column of every table that points at a party, read from the database's own foreign keys.
     *
     * @return list<array{0: string, 1: string}> table, column
     */
    public static function references(): array
    {
        return RequestMemo::remember('party-merger.references', function (): array {
            $references = [];

            foreach (Schema::getTableListing(schemaQualified: false) as $table) {
                foreach (Schema::getForeignKeys($table) as $key) {
                    if (($key['foreign_table'] ?? null) === 'parties' && count($key['columns'] ?? []) === 1) {
                        $references[] = [$table, $key['columns'][0]];
                    }
                }
            }

            return $references;
        });
    }

    /**
     * The rows of a table pointing at the merged parties pointed at the kept one — a row the table refuses
     * (the kept party there already, under a unique key) removed instead. How many moved.
     *
     * @param  list<int>  $ids
     */
    private function repoint(string $table, string $column, array $ids, int $into): int
    {
        $moved = 0;

        foreach (DB::table($table)->whereIn($column, $ids)->get() as $row) {
            $where = (array) $row;
            $query = fn () => DB::table($table)->where(array_filter($where, fn ($value): bool => $value !== null && is_scalar($value)))->limit(1);

            try {
                $moved += $query()->update([$column => $into]);
            } catch (QueryException) {
                $query()->delete();
            }
        }

        return $moved;
    }

    /**
     * The merged parties' rows on the matters: each the kept party's now — or, where the kept party is on that matter
     * in that capacity and on that side already, its representatives moved under the kept party's row and the row
     * removed. How many moved.
     *
     * @param  list<int>  $ids
     */
    private function repointMatterParties(array $ids, int $into): int
    {
        $moved = 0;

        foreach (DB::table('matter_party')->whereIn('party_id', $ids)->orderBy('id')->get() as $row) {
            $held = DB::table('matter_party')
                ->where('matter_id', $row->matter_id)
                ->where('party_id', $into)
                ->where('role', $row->role)
                ->where('type', $row->type)
                ->where('parent_id', $row->parent_id)
                ->orderBy('id')
                ->first();

            if ($held === null) {
                DB::table('matter_party')->where('id', $row->id)->update(['party_id' => $into]);
            } else {
                DB::table('matter_party')->where('parent_id', $row->id)->update(['parent_id' => $held->id]);
                DB::table('matter_party')->where('id', $row->id)->delete();
            }
            $moved++;
        }

        return $moved;
    }

    /**
     * The minutes' attendees (kept as JSON: each with party_id, and represents for one attending for a party).
     *
     * @param  list<int>  $ids
     */
    private function repointAttendees(array $ids, int $into): int
    {
        $moved = 0;

        MatterMinutes::query()->whereNotNull('attendees')->chunkById(100, function (Collection $all) use ($ids, $into, &$moved): void {
            foreach ($all as $minutes) {
                $changed = false;
                $attendees = array_map(function ($attendee) use ($ids, $into, &$changed): mixed {
                    if (! is_array($attendee)) {
                        return $attendee;
                    }
                    foreach (['party_id', 'represents'] as $key) {
                        if (in_array((int) ($attendee[$key] ?? 0), $ids, true)) {
                            $attendee[$key] = $into;
                            $changed = true;
                        }
                    }

                    return $attendee;
                }, (array) $minutes->attendees);

                if ($changed) {
                    $minutes->forceFill(['attendees' => $attendees])->saveQuietly();
                    $moved++;
                }
            }
        });

        return $moved;
    }

    /**
     * The kept party on a matter twice in the same capacity (it and one merged were both on it): the earlier row
     * kept, the representatives of the other moved under it, the other removed. How many removed.
     */
    private function dedupeMatterParties(int $into): int
    {
        $removed = 0;
        $rows = DB::table('matter_party')->where('party_id', $into)->orderBy('id')->get();

        foreach ($rows->groupBy(fn (object $row): string => implode('|', [$row->matter_id, (string) $row->role, (string) $row->type, (string) ($row->parent_id ?? '')])) as $group) {
            $kept = $group->first();

            foreach ($group->skip(1) as $double) {
                DB::table('matter_party')->where('parent_id', $double->id)->update(['parent_id' => $kept->id]);
                DB::table('matter_party')->where('id', $double->id)->delete();
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * The first list with what the second adds to it — alike ones (by the key given) once, as first written.
     *
     * @param  list<string>  $first
     * @param  list<string>  $more
     * @param  callable(string): string  $key
     * @return list<string>
     */
    private static function combined(array $first, array $more, callable $key): array
    {
        $seen = [];
        $all = [];

        foreach ([...$first, ...$more] as $value) {
            $k = $key($value);

            if ($k === '' || isset($seen[$k])) {
                continue;
            }

            $seen[$k] = true;
            $all[] = $value;
        }

        return $all;
    }
}
