<?php

namespace App\Services\MMS;

use App\Models\Matter;
use App\Models\MatterContact;
use App\Models\MatterMinutes;
use App\Models\MatterParty;
use App\Models\Party;
use App\Models\Setting;
use App\Models\Type;
use App\Support\ArabicSearch;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Where an email that came in on a matter is saved, and under what name — by whom it came from: the plaintiff's
 * side, the defendant's, or anyone else (the general one). Each its own folder structure and name, set for the whole
 * system (Settings → OneDrive Folders) and for a matter type of its own; a side left empty is filed as the general
 * one, a name left empty as it always was (SentEmailArchive::emailName()).
 */
final class ReceivedEmailFiling
{
    public const PLAINTIFF = 'plaintiff';

    public const DEFENDANT = 'defendant';

    public const GENERAL = 'general';

    /** The sides that may be filed apart. */
    public const SIDES = [self::PLAINTIFF, self::DEFENDANT];

    /** A side's folder structure: the setting, and the matter type's column. The general one is the received-emails folder (MatterOneDriveFolders). */
    public const FOLDER_SETTINGS = [self::PLAINTIFF => 'onedrive_received_plaintiff_folder', self::DEFENDANT => 'onedrive_received_defendant_folder'];

    public const FOLDER_COLUMNS = [self::PLAINTIFF => 'onedrive_received_plaintiff', self::DEFENDANT => 'onedrive_received_defendant'];

    /** A side's name, and the general one: the setting, and the matter type's column. */
    public const NAME_SETTINGS = [self::PLAINTIFF => 'received_email_name_plaintiff', self::DEFENDANT => 'received_email_name_defendant', self::GENERAL => 'received_email_name'];

    public const NAME_COLUMNS = [self::PLAINTIFF => 'received_name_plaintiff', self::DEFENDANT => 'received_name_defendant', self::GENERAL => 'received_name'];

    /** The name as it always was, written as a pattern. */
    public const DEFAULT_NAME = '{{date}} {{time}} {{kind}} — {{party}} — {{subject}}';

    /**
     * Whose side the sender of an email is on, on this matter: a party of a side, or one of its representatives —
     * among the matter's parties, an address only one side copied in on the matter, or at a meeting of its (the
     * minutes' attendees: for whom they stand, else their capacity). Anyone else: the general one.
     */
    public static function side(Matter $matter, string $address): string
    {
        $address = mb_strtolower(trim($address));

        if ($address === '') {
            return self::GENERAL;
        }

        $sides = self::sides($matter);
        $parties = array_map('intval', MatterContact::partiesWith($address)->all());

        foreach ($parties as $party) {
            if (isset($sides[$party])) {
                return $sides[$party];
            }
        }

        // An address someone copied in on the matter: their side — only when everyone who copied it in is on one
        // side (copied in by both, it says nothing), and it is not of the other side's own (its domain one of their
        // addresses': the plaintiff copied in the defendant's manager).
        $copiedBy = MatterContact::query()->where('matter_id', $matter->getKey())->where('email', $address)->get(['party_id', 'owner_email'])
            ->map(fn (MatterContact $contact): ?string => $sides[(int) $contact->party_id]
                ?? collect(MatterContact::partiesWith((string) $contact->owner_email))->map(fn ($party): ?string => $sides[(int) $party] ?? null)->filter()->first())
            ->unique()
            ->values();

        if ($copiedBy->count() === 1 && $copiedBy->first() !== null && ! in_array(self::domain($address), self::domains($sides, $copiedBy->first() === self::PLAINTIFF ? self::DEFENDANT : self::PLAINTIFF), true)) {
            return $copiedBy->first();
        }

        // At a meeting: the latest minutes first.
        $minutes = MatterMinutes::query()->where('matter_id', $matter->getKey())->whereNotNull('attendees')->latest('id')->pluck('attendees');

        foreach ($minutes as $attendees) {
            foreach ((array) $attendees as $attendee) {
                $theirs = is_array($attendee) && (mb_strtolower(trim((string) ($attendee['email'] ?? ''))) === $address
                    || (filled($attendee['party_id'] ?? null) && in_array((int) $attendee['party_id'], $parties, true)));

                if (! $theirs) {
                    continue;
                }

                $side = $sides[(int) ($attendee['represents'] ?? 0)] ?? $sides[(int) ($attendee['party_id'] ?? 0)] ?? self::sideNamed($matter, (string) ($attendee['capacity'] ?? ''));

                if ($side !== null) {
                    return $side;
                }
            }
        }

        return self::GENERAL;
    }

    /** The folder structure for a side's emails on this matter: its type's own, else the setting; empty, the general one. */
    public static function folderTemplate(?Matter $matter, string $side): string
    {
        $own = isset(self::FOLDER_COLUMNS[$side]) ? self::own($matter, self::FOLDER_COLUMNS[$side], self::FOLDER_SETTINGS[$side]) : '';

        return $own !== '' ? $own : MatterOneDriveFolders::receivedEmailsTemplate($matter);
    }

    /** The name pattern for a side's emails on this matter: its type's own, else the setting; empty, the general one's; empty too, the name as it always was. */
    public static function nameTemplate(?Matter $matter, string $side): string
    {
        $own = $side !== self::GENERAL && isset(self::NAME_COLUMNS[$side]) ? self::own($matter, self::NAME_COLUMNS[$side], self::NAME_SETTINGS[$side]) : '';

        return $own !== '' ? $own : self::own($matter, self::NAME_COLUMNS[self::GENERAL], self::NAME_SETTINGS[self::GENERAL]);
    }

    /**
     * An email's name, before ".pdf": its pattern with {{date}}, {{time}}, {{kind}} (incoming, reply), {{party}} (who
     * sent it), {{subject}} (what about, shortened), {{side}} (the side's name on the matter's type) and the matter's
     * placeholders filled in — a part that comes out empty left out, with its " — ". Kept short, nothing a file name
     * can't hold (SentEmailArchive::NAME_LIMIT).
     */
    public static function name(?Matter $matter, string $side, string $party, string $about, CarbonInterface $at, bool $reply): string
    {
        $template = self::nameTemplate($matter, $side);

        if ($template === '') {
            return SentEmailArchive::emailName(false, $party, $about, $at, $reply);
        }

        $at = Carbon::instance($at)->setTimezone(config('app.timezone'));
        $values = [
            ...(str_contains($template, '{{matter.') ? BulkMailPlaceholders::forMatter($matter) : []),
            'date' => $at->format('Y-m-d'),
            'time' => $at->format('H.i'),
            'kind' => $reply ? __('Reply') : __('Incoming'),
            'party' => Str::limit(trim((string) preg_replace('/\s+/u', ' ', $party)), SentEmailArchive::PARTY_LIMIT, '…'),
            'subject' => SentEmailArchive::topic($about),
            'side' => $side === self::GENERAL ? '' : Type::sideLabel($matter?->type, $side),
        ];

        $name = (string) preg_replace('/\{\{[^{}]*\}\}/u', '', BulkMailPlaceholders::apply($template, $values));
        // An empty part's separator left out: "a —  — b" is "a — b".
        $name = trim((string) preg_replace(['/\s*(?:—\s*)+/u', '/\s+/u'], [' — ', ' '], $name), " —-_");

        return MatterOneDriveExplorer::cleanName(Str::limit($name !== '' ? $name : 'email', SentEmailArchive::NAME_LIMIT, '…'));
    }

    /**
     * The side of each of the matter's parties and their representatives — party id => side.
     *
     * @return array<int, string>
     */
    private static function sides(Matter $matter): array
    {
        $rows = MatterParty::query()->where('matter_id', $matter->getKey())->whereIn('role', ['party', 'representative'])
            ->get(['id', 'party_id', 'parent_id', 'role', 'type'])->keyBy('id');
        $sides = [];

        foreach ($rows as $row) {
            // A representative: the side of the party they stand for — else the one they are typed with.
            $type = $row->role === 'representative' ? ($rows->get($row->parent_id)?->type ?? $row->type) : $row->type;

            if (in_array($type, self::SIDES, true) && $row->party_id) {
                $sides[(int) $row->party_id] ??= $type;
            }
        }

        return $sides;
    }

    /**
     * The domains of a side's addresses — its parties' and representatives' — not a public mail service's.
     *
     * @param  array<int, string>  $sides  party id => side (sides())
     * @return list<string>
     */
    private static function domains(array $sides, string $side): array
    {
        $parties = array_keys(array_filter($sides, fn (string $of): bool => $of === $side));

        return Party::query()->whereKey($parties)->pluck('email')
            ->flatMap(fn ($emails): array => array_map(fn (string $email): string => self::domain($email), Party::values($emails)))
            ->reject(fn (string $domain): bool => $domain === '' || in_array($domain, MatterContact::SHARED_DOMAINS, true))
            ->unique()->values()->all();
    }

    /** An address's domain, as "@example.com". */
    private static function domain(string $address): string
    {
        $address = mb_strtolower(trim($address));

        return str_contains($address, '@') ? substr($address, (int) strrpos($address, '@')) : '';
    }

    /** A side by its name in an attendee's capacity ("المدعى عليه", "وكيل المستأنف ضده") — the defendant's looked for first, its name often holding the plaintiff's. */
    private static function sideNamed(Matter $matter, string $capacity): ?string
    {
        $capacity = ArabicSearch::normalize(trim($capacity));

        if ($capacity === '') {
            return null;
        }

        foreach ([self::DEFENDANT, self::PLAINTIFF] as $side) {
            $names = array_filter([$matter->type?->capacity($side), __(ucfirst($side), [], 'ar'), __(ucfirst($side), [], 'en')]);

            foreach ($names as $name) {
                if (mb_stripos($capacity, ArabicSearch::normalize($name)) !== false) {
                    return $side;
                }
            }
        }

        return null;
    }

    /** The matter type's own (its column), else the setting. */
    private static function own(?Matter $matter, string $column, string $setting): string
    {
        $own = $matter ? trim((string) $matter->loadMissing('type')->type?->getAttribute($column)) : '';

        return $own !== '' ? $own : trim((string) Setting::get($setting, ''));
    }
}
