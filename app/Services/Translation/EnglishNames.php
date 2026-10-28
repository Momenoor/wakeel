<?php

namespace App\Services\Translation;

use App\Models\ExpertiseArea;
use App\Models\HrListItem;
use App\Models\Party;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Throwable;

/**
 * The English names the system fills in from the Arabic ones (NameTranslator): a party's name in English —
 * which English letters name them by, an employee too — and the English name of an entry of the HR lists or
 * an expertise area that still has its Arabic in it (as the lists began, the one name in both). Filled after
 * a party is saved without one (later()), and all at once from Settings → Integrations (fill()). A name typed
 * in is never replaced.
 */
class EnglishNames
{
    /** @var array<int, true> parties saved this request, translated once the answer has gone back */
    private static array $later = [];

    public function __construct(private NameTranslator $translator) {}

    /** After the answer has gone back: the party's name in English, when it has none and its name is in Arabic. */
    public static function later(Party $party): void
    {
        if (! NameTranslator::on() || filled($party->name_en) || ! NameTranslator::arabic($party->name)) {
            return;
        }

        $first = self::$later === [];
        self::$later[(int) $party->getKey()] = true;

        // One request for all those saved together (an import, a matter with its parties), not one each.
        if ($first) {
            defer(fn () => app(self::class)->fillLater());
        }
    }

    public function fillLater(): void
    {
        $ids = array_keys(self::$later);
        self::$later = [];

        try {
            $this->fillParties(self::pendingParties($ids));
        } catch (Throwable $e) {
            // Left for "Translate names now"; the party is saved as it is.
            report($e);
        }
    }

    /**
     * For about $seconds, a hundred names a request: the parties, then the lists' entries and the expertise
     * areas. How many were translated, and how many are left.
     *
     * @return array{translated: int, left: int}
     */
    public function fill(int $seconds): array
    {
        $until = microtime(true) + $seconds;
        $translated = 0;

        foreach ([self::pendingParties(), ...self::pendingLists()] as $records) {
            foreach ($records->chunk(100) as $chunk) {
                if (microtime(true) >= $until) {
                    return ['translated' => $translated, 'left' => self::left()];
                }

                $translated += $chunk->first() instanceof Party ? $this->fillParties($chunk) : $this->fillList($chunk);
            }
        }

        return ['translated' => $translated, 'left' => self::left()];
    }

    /** The names still to be put into English. */
    public static function left(): int
    {
        return self::pendingParties()->count()
            + collect(self::pendingLists())->sum(fn (Collection $records): int => $records->count());
    }

    /**
     * Parties with a name in Arabic and none in English — of $ids, else all (read without their other columns;
     * the Arabic told apart here, as the databases' patterns are not the same).
     *
     * @param  list<int>|null  $ids
     * @return Collection<int, Party>
     */
    private static function pendingParties(?array $ids = null): Collection
    {
        return Party::query()
            ->when($ids !== null, fn (Builder $query) => $query->whereKey($ids))
            ->where(fn (Builder $query) => $query->whereNull('name_en')->orWhere('name_en', ''))
            ->orderBy('id')
            ->get(['id', 'name', 'name_en'])
            ->filter(fn (Party $party): bool => NameTranslator::arabic($party->name))
            ->values();
    }

    /**
     * @param  Collection<int, Party>  $parties
     */
    private function fillParties(Collection $parties): int
    {
        $english = $this->translator->translate($parties->pluck('name'));

        $done = 0;

        foreach ($parties as $party) {
            if ($name = $english[trim((string) $party->name)] ?? null) {
                // Quietly: no entry in the activity log for each of thousands, and not translated again on save.
                Party::whereKey($party->getKey())->update(['name_en' => $name]);
                $done++;
            }
        }

        return $done;
    }

    /**
     * The lists' entries and expertise areas whose English name is still in Arabic.
     *
     * @return list<Collection<int, Model>>
     */
    private static function pendingLists(): array
    {
        return [
            HrListItem::query()->get(['id', 'kind', 'name_en', 'name_ar'])->filter(fn (HrListItem $item): bool => NameTranslator::arabic($item->name_en))->values(),
            ExpertiseArea::query()->get(['id', 'name_en', 'name_ar'])->filter(fn (ExpertiseArea $area): bool => NameTranslator::arabic($area->name_en))->values(),
        ];
    }

    /**
     * @param  Collection<int, Model>  $records
     */
    private function fillList(Collection $records): int
    {
        $source = fn (Model $record): string => trim((string) ($record->getAttribute('name_ar') ?: $record->getAttribute('name_en')));
        $english = $this->translator->translate($records->map($source));

        $done = 0;

        foreach ($records as $record) {
            if (! $name = $english[$source($record)] ?? null) {
                continue;
            }

            try {
                $record->newQuery()->whereKey($record->getKey())->update(['name_en' => $name]);
                $done++;
            } catch (QueryException) {
                // Another entry of the list has that English name already: this one is left for its page.
            }
        }

        return $done;
    }
}
