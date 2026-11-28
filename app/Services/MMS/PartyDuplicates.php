<?php

namespace App\Services\MMS;

use App\Models\Party;
use App\Models\Setting;
use App\Support\PartyNames;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * The parties entered more than once, for Maintenance → Duplicate parties and parties:duplicates: those of the same
 * name however typed (PartyNames::key()), and — to look at — those a letter or two apart, each group at one level
 * (under the same entity, or under none). For each, the party to keep (one with an assigned user, else an English
 * name, else the most phones and emails, else the oldest), the name it is to have (the fullest of theirs), and why
 * to look before merging. A group said to be different parties is not shown again (dismiss()).
 */
final class PartyDuplicates
{
    public const SAME = 'same';

    public const CLOSE = 'close';

    /** The groups said to be different parties: each its parties' ids, sorted, joined. */
    public const DISMISSED = 'party_duplicates_dismissed';

    private const CACHE = 'party-duplicates.groups';

    /**
     * The groups of a kind, each by a key of its own.
     *
     * @return array<string, array{key: string, kind: string, ids: list<int>, keep: int, final_name: string, check: list<string>}>
     */
    public function groups(string $kind = self::SAME): array
    {
        $all = Cache::remember(self::CACHE, now()->addMinutes(10), fn (): array => $this->find());
        $dismissed = array_flip((array) Setting::get(self::DISMISSED, []));

        return array_filter($all[$kind] ?? [], fn (array $group): bool => ! isset($dismissed[self::signature($group['ids'])]));
    }

    /** Looked for again, the next time they are asked for (after a merge, or new parties). */
    public function forget(): void
    {
        Cache::forget(self::CACHE);
    }

    /**
     * The parties of a group, the one to keep first.
     *
     * @param  array{ids: list<int>, keep: int}  $group
     * @return Collection<int, Party>
     */
    public function members(array $group): Collection
    {
        return Party::query()->findMany($group['ids'])->sortBy(fn (Party $p): array => [$p->getKey() !== $group['keep'], $p->getKey()])->values();
    }

    /** A group said to be different parties: not shown again. */
    public function dismiss(array $ids): void
    {
        $dismissed = (array) Setting::get(self::DISMISSED, []);
        $dismissed[] = self::signature($ids);
        Setting::set(self::DISMISSED, array_values(array_unique($dismissed)), 'maintenance');
    }

    /**
     * The parties merged into the one kept (PartyMerger), named as given — else the fullest of their names.
     *
     * @param  list<int>  $ids
     * @return array{moved: array<string, int>, removed: int}
     */
    public function merge(int $keep, array $ids, ?string $name = null): array
    {
        $into = Party::query()->findOrFail($keep);
        $result = app(PartyMerger::class)->merge($into, array_values(array_diff(array_map('intval', $ids), [$keep])));

        if (filled($name)) {
            $into->refresh()->update(['name' => $name]);
        }

        $this->forget();

        return $result;
    }

    /** @param list<int> $ids */
    public static function signature(array $ids): string
    {
        $ids = array_map('intval', $ids);
        sort($ids);

        return implode(',', $ids);
    }

    /**
     * @return array{same: array<string, array<string, mixed>>, close: array<string, array<string, mixed>>}
     */
    private function find(): array
    {
        $parties = Party::query()
            ->whereNotNull('name_key')->where('name_key', '!=', '')
            ->orderBy('id')
            ->get(['id', 'name', 'name_key', 'name_en', 'parent_id', 'phone', 'email', 'user_id']);

        $byKey = $parties->groupBy(fn (Party $p): string => ($p->parent_id ?? '').'|'.$p->name_key);
        $same = [];

        foreach ($byKey->filter(fn ($group): bool => $group->count() > 1) as $level => $group) {
            $same['s'.md5($level)] = $this->describe('s'.md5($level), self::SAME, $group);
        }

        return ['same' => $same, 'close' => $this->close($byKey)];
    }

    /**
     * The names a letter or two apart (PartyNames::close()), each name compared with those beginning or ending as it
     * does, at its level — a group with every party of each name in it.
     *
     * @param  \Illuminate\Support\Collection<string, \Illuminate\Support\Collection<int, Party>>  $byKey
     * @return array<string, array<string, mixed>>
     */
    private function close($byKey): array
    {
        $blocks = [];

        foreach ($byKey->keys() as $level) {
            [$parent, $key] = explode('|', $level, 2);
            $blocks[$parent.'|<'.mb_substr($key, 0, 2)][] = $level;
            $blocks[$parent.'|>'.mb_substr($key, -2)][] = $level;
        }

        $root = [];
        $find = function (string $x) use (&$root, &$find): string {
            return ($root[$x] ?? $x) === $x ? $x : ($root[$x] = $find($root[$x]));
        };

        foreach ($blocks as $levels) {
            $count = count($levels);

            for ($i = 0; $i < $count; $i++) {
                for ($j = $i + 1; $j < $count; $j++) {
                    $a = explode('|', $levels[$i], 2)[1];
                    $b = explode('|', $levels[$j], 2)[1];

                    if ($a !== $b && PartyNames::close($a, $b)) {
                        $root[$find($levels[$i])] = $find($levels[$j]);
                    }
                }
            }
        }

        $groups = [];

        foreach (array_keys($root) as $level) {
            $groups[$find($level)][] = $level;
        }

        $out = [];

        foreach ($groups as $top => $levels) {
            $members = collect(array_unique([$top, ...$levels]))->flatMap(fn (string $level) => $byKey[$level])->values();
            $out['c'.md5($top)] = $this->describe('c'.md5($top), self::CLOSE, $members);
        }

        return $out;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Party>  $group
     * @return array{key: string, kind: string, ids: list<int>, keep: int, final_name: string, check: list<string>}
     */
    private function describe(string $key, string $kind, $group): array
    {
        $keep = $group->sortBy(fn (Party $p): array => [blank($p->user_id), blank($p->name_en), -(count(Party::values($p->phone)) + count(Party::values($p->email))), $p->id])->first();
        $emails = $group->map(fn (Party $p): array => array_map('mb_strtolower', Party::values($p->email)))->filter()->values();
        $phones = $group->map(fn (Party $p): array => array_map(fn (string $v): string => substr((string) preg_replace('/\D+/', '', $v), -9), Party::values($p->phone)))->filter()->values();
        $apart = fn ($sets): bool => $sets->contains(fn (array $a) => $sets->contains(fn (array $b) => array_intersect($a, $b) === []));
        $words = $group->max(fn (Party $p): int => count(explode(' ', (string) $p->name)));

        return [
            'key' => $key,
            'kind' => $kind,
            'ids' => $group->pluck('id')->map(fn ($id): int => (int) $id)->values()->all(),
            'keep' => (int) $keep->id,
            'final_name' => PartyMerger::fullest([$keep->name, ...$group->pluck('name')->all()]),
            'check' => array_values(array_filter([
                $words <= 1 ? __('One-word name') : null,
                $apart($emails) || $apart($phones) ? __('Different phones or emails') : null,
                $group->pluck('user_id')->filter()->unique()->count() > 1 ? __('Two assigned users') : null,
            ])),
        ];
    }
}
