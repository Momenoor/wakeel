<?php

namespace App\Services\MMS;

use App\Models\Matter;
use App\Models\MatterEmail;
use App\Models\MatterParty;
use App\Models\Party;
use App\Services\MMS\Calendar\MatterReferenceMatcher;
use Closure;

/**
 * The matter an incoming email is about — only when certain.
 *
 * It must come from one of the matters' parties (or their representatives,
 * attendees …: anyone on a matter but its experts), by its email address.
 * Their matters are the candidates; then, in turn, until one says which:
 *
 *   1. a candidate's number written in the subject ("639/2025") — or a matter
 *      the sender is not on, when the subject names exactly one matter in the
 *      system (a lawyer writing about a case they were not added to yet),
 *   2. … or in the email's text,
 *   3. the name of a candidate's party (not the sender's own) in either,
 *   4. the sender has but the one matter — and the email names no other.
 *
 * When a step points at more than one candidate, or none settles it, a
 * person chooses (the Unfiled emails page): an address on several matters
 * is never enough on its own.
 */
class IncomingEmailMatcher
{
    /** A party's name shorter than this is not looked for in an email. */
    private const NAME_MIN = 5;

    /** @var array<string, list<int>>|null address => the parties that have it */
    private ?array $byAddress = null;

    /**
     * matter: when certain; else candidates for a person to choose from —
     * none: not a matter's email.
     *
     * @return array{matter: ?int, candidates: list<int>, by: ?string}
     */
    public function match(string $from, string $subject, Closure|string $body): array
    {
        $none = ['matter' => null, 'candidates' => [], 'by' => null];
        $parties = $this->partiesWith($from);

        if ($parties === []) {
            return $none;
        }

        // The sender's matters — on them as anything but an expert.
        $candidates = MatterParty::query()
            ->whereIn('party_id', $parties)
            ->where(fn ($q) => $q->whereNull('role')->orWhere('role', '!=', 'expert'))
            ->whereHas('matter')
            ->distinct()
            ->pluck('matter_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($candidates === []) {
            return $none;
        }

        $text = null;
        $bodyText = function () use (&$text, $body): string {
            return $text ??= self::plain($body instanceof Closure ? (string) $body() : $body);
        };

        $steps = [
            MatterEmail::BY_SUBJECT => fn (): array => self::numbered($subject),
            MatterEmail::BY_BODY => fn (): array => self::numbered($bodyText()),
            MatterEmail::BY_PARTY_NAME => fn (): array => self::named($candidates, $parties, $subject.' '.$bodyText()),
        ];

        $other = false;

        foreach ($steps as $by => $find) {
            $found = $find();
            $mine = array_values(array_intersect($candidates, $found));

            // The subject names one matter in the system: it is that matter's, whether or not the sender was added to it.
            if ($mine === [] && $by === MatterEmail::BY_SUBJECT && $found !== []) {
                // …and when several share the number, a person picks among those.
                return ['matter' => count($found) === 1 ? $found[0] : null, 'candidates' => $found, 'by' => count($found) === 1 ? $by : null];
            }

            if (count($mine) === 1) {
                return ['matter' => $mine[0], 'candidates' => $mine, 'by' => $by];
            }

            if (count($mine) > 1) {
                return ['matter' => null, 'candidates' => $mine, 'by' => null];
            }

            // The text (not the subject) names one matter in the system that is not the sender's: also that matter's, once the sender is a party somewhere.
            if ($mine === [] && $by === MatterEmail::BY_BODY && count($found) === 1) {
                return ['matter' => $found[0], 'candidates' => $found, 'by' => $by];
            }

            // A number of a matter not the sender's: not sure it's theirs.
            $other = $other || ($by !== MatterEmail::BY_PARTY_NAME && $found !== []);
        }

        if (count($candidates) === 1 && ! $other) {
            return ['matter' => $candidates[0], 'candidates' => $candidates, 'by' => MatterEmail::BY_ADDRESS];
        }

        return ['matter' => null, 'candidates' => $candidates, 'by' => null];
    }

    /**
     * The parties with this email address.
     *
     * @return list<int>
     */
    public function partiesWith(string $address): array
    {
        $address = mb_strtolower(trim($address));

        if ($this->byAddress === null) {
            $this->byAddress = [];

            Party::query()->whereNotNull('email')->select(['id', 'email'])->chunkById(1000, function ($parties) {
                foreach ($parties as $party) {
                    foreach (Party::values($party->email) as $email) {
                        $this->byAddress[mb_strtolower($email)][] = (int) $party->id;
                    }
                }
            });
        }

        return $address === '' ? [] : array_values(array_unique($this->byAddress[$address] ?? []));
    }

    /**
     * Every matter whose number is written in the text — all that share a
     * number, not one picked.
     *
     * @return list<int>
     */
    public static function numbered(string $text): array
    {
        $refs = MatterReferenceMatcher::references($text);

        if ($refs === []) {
            return [];
        }

        return collect(MatterReferenceMatcher::allMattersFor($refs))->flatMap(fn ($same) => $same->pluck('id'))->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    /**
     * The candidates one of whose parties (not the sender's own) is named in
     * the text.
     *
     * @param  list<int>  $candidates
     * @param  list<int>  $senders
     * @return list<int>
     */
    public static function named(array $candidates, array $senders, string $text): array
    {
        $text = self::normal($text);
        $found = [];

        MatterParty::query()
            ->whereIn('matter_id', $candidates)
            ->where('role', 'party')
            ->whereNotIn('party_id', $senders)
            ->with('party:id,name,legal_name')
            ->get(['id', 'matter_id', 'party_id'])
            ->each(function (MatterParty $row) use ($text, &$found) {
                foreach ([$row->party?->name, $row->party?->legal_name] as $name) {
                    $name = self::normal((string) $name);

                    if (mb_strlen($name) >= self::NAME_MIN && str_contains($text, $name)) {
                        $found[] = (int) $row->matter_id;

                        return;
                    }
                }
            });

        return array_values(array_unique($found));
    }

    /** An email's HTML as its words. */
    public static function plain(string $html): string
    {
        $html = (string) preg_replace('~<(style|script|head)\b.*?</\1>~is', ' ', $html);
        $text = html_entity_decode(strip_tags(str_replace(['<br', '</p>', '</div>'], ["\n<br", "\n</p>", "\n</div>"], $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/[ \t\x{00A0}]+/u', ' ', $text));
    }

    /** For comparing names: one way of writing Arabic letters, no marks, one space. */
    public static function normal(string $text): string
    {
        $text = strtr(mb_strtolower($text), ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا', 'ة' => 'ه', 'ى' => 'ي', 'ـ' => '']);
        $text = (string) preg_replace('/[\x{064B}-\x{065F}\x{0670}]/u', '', $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
