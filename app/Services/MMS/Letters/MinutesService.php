<?php

namespace App\Services\MMS\Letters;

use App\Models\Letterhead;
use App\Models\LetterTemplate;
use App\Models\MatterMinutes;
use App\Models\MatterParty;
use App\Models\Party;
use App\Services\MMS\MatterProgressRecorder;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * A meeting's minutes, as a document: its template filled with the matter,
 * the meeting (its date, day, time and link), who attended and the
 * questions and answers — on the letterhead, as PDF or Word, like a
 * letter. Finalised, its wording is kept and its PDF filed with the
 * matter's attachments.
 */
class MinutesService
{
    public static function composer(MatterMinutes $minutes): LetterComposer
    {
        $minutes->loadMissing(['template', 'matter', 'letterhead']);

        // The wording kept when it was finalised; until then, the template's.
        $template = ($minutes->template ?? new LetterTemplate(['locale' => 'ar']))->replicate();
        if ($minutes->isFinal() && filled($minutes->body)) {
            $template->body = (string) $minutes->body;
        }

        $arabic = ($template->locale ?: 'ar') !== 'en';

        return new LetterComposer(
            $template,
            $minutes->matter,
            [
                ...($minutes->inputs ?? []),
                LetterComposer::MEETING_START => $minutes->meeting_at?->format('Y-m-d H:i'),
                LetterComposer::MEETING_LINK => $minutes->meeting_link,
                LetterComposer::MINUTES => [
                    'number' => $minutes->number,
                    'attendees' => $minutes->attendees ?? [],
                    'items' => $minutes->items ?? [],
                    'opening' => self::opening($minutes),
                    'closing' => self::closing($minutes),
                    'ended_at' => $minutes->ended_at?->format('Y-m-d H:i'),
                ],
            ],
            [],
            $arabic ? 'محضر رقم ('.$minutes->number.')' : 'Minutes No. '.$minutes->number,
            $minutes->meeting_at ?? $minutes->created_at ?? now(),
            $minutes->letterhead ?? $template->letterhead ?? Letterhead::default(),
        );
    }

    /**
     * The opening paragraph as written while recording — until then, the
     * template's.
     */
    public static function opening(MatterMinutes $minutes): string
    {
        return (string) ($minutes->opening ?? $minutes->template?->minutes_opening);
    }

    /**
     * The closing paragraph as written while recording — until then, the
     * template's.
     */
    public static function closing(MatterMinutes $minutes): string
    {
        return (string) ($minutes->closing ?? $minutes->template?->minutes_closing);
    }

    /**
     * What was recorded at the meeting, kept: the meeting's time and link,
     * who attended, the questions and answers, the template's own fields.
     * Also while typing (the live view's autosave): nothing is required.
     *
     * @param  array<string, mixed>  $data
     */
    public static function saveRecorded(MatterMinutes $minutes, array $data): void
    {
        $minutes->update([
            'meeting_at' => filled($data['meeting_at'] ?? null) ? Carbon::parse($data['meeting_at']) : $minutes->meeting_at,
            'meeting_link' => $data['meeting_link'] ?? null,
            'attendees' => array_values(array_map(fn (array $a): array => [
                'present' => (bool) ($a['present'] ?? false),
                'title' => $a['title'] ?? null,
                'name' => (string) ($a['name'] ?? ''),
                'capacity' => $a['capacity'] ?? null,
                'id_number' => $a['id_number'] ?? null,
                'phone' => $a['phone'] ?? null,
                'email' => filled($a['email'] ?? null) ? trim((string) $a['email']) : null,
                'party_id' => filled($a['party_id'] ?? null) ? (int) $a['party_id'] : null,
                // The main party they stand for, and how (lawyer, employee …).
                // Never themselves.
                'represents' => filled($a['represents'] ?? null) && (int) $a['represents'] !== (int) ($a['party_id'] ?? 0) ? (int) $a['represents'] : null,
                'as' => filled($a['as'] ?? null) ? (string) $a['as'] : null,
            ], array_filter((array) ($data['attendees'] ?? []), 'is_array'))),
            'items' => array_values(array_map(fn (array $item): array => [
                'type' => ($item['type'] ?? 'question') === 'comment' ? 'comment' : 'question',
                'text' => trim((string) ($item['text'] ?? '')),
                'answer' => ($item['type'] ?? 'question') === 'comment' ? null : (filled($item['answer'] ?? null) ? trim((string) $item['answer']) : null),
            ], array_filter((array) ($data['items'] ?? []), 'is_array'))),
            'inputs' => (array) ($data['inputs'] ?? $minutes->inputs ?? []),
            'opening' => array_key_exists('opening', $data) ? (string) $data['opening'] : $minutes->opening,
            'closing' => array_key_exists('closing', $data) ? (string) $data['closing'] : $minutes->closing,
        ]);
    }

    /**
     * The minutes as they read now, for the live view shown to the
     * attendees: the same text as the PDF, without the signature and stamp
     * (files on the server, not for the screen).
     */
    public static function liveHtml(MatterMinutes $minutes): string
    {
        $html = preg_replace('/<img\b[^>]*>/i', '', self::composer($minutes)->bodyHtml()) ?? '';

        // Sizes as written (pt) relative to the text's 12 pt — so they keep
        // their proportions on the screen and grow with its A+ / A−.
        return preg_replace_callback(
            '/font-size:\s*([\d.]+)pt/i',
            fn (array $m) => 'font-size: '.round((float) $m[1] / 12, 4).'em',
            $html,
        ) ?? $html;
    }

    /**
     * Who may attend: the matter's parties and their representatives, each
     * not yet marked present, with the phone and ID number known for them.
     *
     * @return list<array{present: bool, title: string, name: string, capacity: ?string, id_number: ?string, phone: ?string, party_id: ?int}>
     */
    public static function attendeeCandidates(MatterMinutes $minutes): array
    {
        $arabic = self::arabic($minutes);
        $candidates = LetterComposer::candidates($minutes->matter, $arabic);
        $parties = Party::query()->whereIn('id', collect($candidates)->pluck('party_id')->filter())->get()->keyBy('id');

        return collect($candidates)
            ->map(fn (array $c): array => [
                'present' => false,
                'title' => self::isCompany($c['name']) ? ($arabic ? 'السادة/' : 'Messrs.') : ($arabic ? 'الأستاذ/' : 'Mr.'),
                'name' => $c['name'],
                'capacity' => $c['role'],
                'id_number' => $parties->get($c['party_id'])?->extra['id_number'] ?? null,
                'phone' => $parties->get($c['party_id'])?->latestPhone(),
                'email' => $parties->get($c['party_id'])?->latestEmail(),
                'party_id' => $c['party_id'],
                // A representative stands for their party; a party for itself.
                'represents' => filled($c['of'] ?? null) && filled($candidates[$c['of']]['party_id'] ?? null) ? (int) $candidates[$c['of']]['party_id'] : null,
                'as' => filled($c['of'] ?? null) ? 'agent' : null,
            ])
            ->values()
            ->all();
    }

    /**
     * Whom a meeting not yet recorded starts with: the previous meeting's
     * attendees (the same ones present, standing for the same parties),
     * their phone and email as known now — then any of the matter's parties
     * since added. The first meeting: the parties (attendeeCandidates).
     *
     * @return list<array<string, mixed>>
     */
    public static function startingAttendees(MatterMinutes $minutes): array
    {
        $candidates = self::attendeeCandidates($minutes);

        $previous = MatterMinutes::query()
            ->where('matter_id', $minutes->matter_id)
            ->whereKeyNot($minutes->getKey())
            ->where('id', '<', $minutes->getKey())
            ->whereNotNull('attendees')
            ->latest('id')
            ->get()
            ->first(fn (MatterMinutes $m): bool => collect($m->attendees)->contains(fn ($a) => is_array($a) && filled($a['name'] ?? null)));

        if (! $previous) {
            return $candidates;
        }

        $attendees = collect($previous->attendees)->filter(fn ($a) => is_array($a) && filled($a['name'] ?? null))->values();
        $parties = Party::query()->whereIn('id', $attendees->pluck('party_id')->filter())->get()->keyBy('id');

        $attendees = $attendees->map(function (array $a) use ($parties): array {
            $party = filled($a['party_id'] ?? null) ? $parties->get((int) $a['party_id']) : null;

            return [
                'present' => (bool) ($a['present'] ?? false),
                'title' => $a['title'] ?? null,
                'name' => (string) $a['name'],
                'capacity' => $a['capacity'] ?? null,
                'id_number' => ($party?->extra['id_number'] ?? null) ?: ($a['id_number'] ?? null),
                'phone' => $party?->latestPhone() ?: ($a['phone'] ?? null),
                'email' => $party?->latestEmail() ?: ($a['email'] ?? null),
                'party_id' => $party?->getKey() ?? (filled($a['party_id'] ?? null) ? (int) $a['party_id'] : null),
                'represents' => $a['represents'] ?? null,
                'as' => $a['as'] ?? null,
            ];
        });

        $listed = $attendees->pluck('party_id')->filter()->map(fn ($id) => (int) $id)->all();
        $added = collect($candidates)->filter(fn (array $c): bool => blank($c['party_id']) || ! in_array((int) $c['party_id'], $listed, true));

        return $attendees->concat($added)->values()->all();
    }

    /**
     * Each attendee kept with the matter: one added by hand becomes a party
     * (the one of that name, else a new one of role "attendee", with the ID,
     * phone and email typed), and whoever isn't yet the matter's is linked
     * to it as an attendee — under the party they attended for. The party
     * is written back on the attendee, so it's found next time.
     */
    public static function registerAttendees(MatterMinutes $minutes): void
    {
        $matter = $minutes->matter;
        if (! $matter) {
            return;
        }

        $matter->loadMissing('matterParties.party');
        $rows = $matter->matterParties;
        $byName = $rows->pluck('party')->filter()->keyBy(fn (Party $party) => self::nameKey((string) $party->name));
        $changed = false;

        $attendees = collect($minutes->attendees ?? [])->map(function ($attendee) use ($matter, &$rows, &$byName, &$changed) {
            if (! is_array($attendee) || blank($name = trim((string) ($attendee['name'] ?? '')))) {
                return $attendee;
            }

            $party = filled($attendee['party_id'] ?? null) ? Party::find($attendee['party_id']) : null;

            if (! $party) {
                $party = $byName->get(self::nameKey($name))
                    ?? Party::query()->where('name', $name)->oldest('id')->first()
                    ?? Party::create([
                        'name' => $name,
                        'role' => ['role' => ['attendee']],
                        'extra' => filled($attendee['id_number'] ?? null) ? ['id_number' => trim((string) $attendee['id_number'])] : null,
                    ]);

                $attendee['party_id'] = $party->getKey();
                $byName->put(self::nameKey($name), $party);
                $changed = true;
            }

            $represents = filled($attendee['represents'] ?? null) && (int) $attendee['represents'] !== (int) $party->getKey()
                ? (int) $attendee['represents'] : null;

            // Whom they came for, their party's parent — one of no one yet, and
            // no party, representative or expert in their own right (a lawyer
            // stands for many).
            if ($represents && blank($party->parent_id) && ! collect((array) ($party->role['role'] ?? []))->intersect(['party', 'representative', 'expert'])->isNotEmpty()) {
                $party->update(['parent_id' => $represents]);
            }

            $under = $represents
                ? $rows->first(fn (MatterParty $mp) => (int) $mp->party_id === $represents && $mp->role !== 'attendee')
                : null;
            $row = $rows->first(fn (MatterParty $mp) => (int) $mp->party_id === (int) $party->getKey());

            if (! $row) {
                $rows->push($matter->matterParties()->create([
                    'party_id' => $party->getKey(),
                    'role' => 'attendee',
                    'type' => 'attendee',
                    'parent_id' => $under?->getKey(),
                ])->setRelation('party', $party));
            } elseif ($row->role === 'attendee' && $under && (int) $row->parent_id !== (int) $under->getKey()) {
                // Kept under whom they now came for.
                $row->update(['parent_id' => $under->getKey()]);
            }

            return $attendee;
        });

        if ($changed) {
            $minutes->update(['attendees' => $attendees->values()->all()]);
        }
    }

    private static function arabic(MatterMinutes $minutes): bool
    {
        return (($minutes->template?->locale) ?: 'ar') !== 'en';
    }

    /**
     * Whom an attendee can stand for: the matter's main parties and their
     * representatives — party id => "السادة/ name - capacity" (المدعي,
     * وكيل المدعي …).
     *
     * @return array<int, string>
     */
    public static function mainParties(MatterMinutes $minutes): array
    {
        return collect(LetterComposer::candidates($minutes->matter, self::arabic($minutes)))
            ->filter(fn (array $c): bool => filled($c['party_id'] ?? null))
            ->unique('party_id')
            ->mapWithKeys(fn (array $c): array => [(int) $c['party_id'] => self::standsForLabel($minutes, $c)])
            ->all();
    }

    /**
     * "السادة/ مكتب محمد البنا للمحاماة - وكيل المدعي".
     *
     * @param  array<string, mixed>  $candidate
     */
    private static function standsForLabel(MatterMinutes $minutes, array $candidate): string
    {
        $arabic = self::arabic($minutes);
        $title = self::isCompany((string) $candidate['name']) ? ($arabic ? 'السادة/' : 'Messrs.') : ($arabic ? 'الأستاذ/' : 'Mr.');

        return trim($title.' '.$candidate['name'].(filled($candidate['role'] ?? null) ? ' - '.$candidate['role'] : ''));
    }

    /**
     * How an attendee stands for a main party: its agent or authorised by
     * it. The choices of minutes recorded before stay readable — `$current`
     * keeps the one a line has.
     *
     * @return array<string, string>
     */
    public static function attendeeRoles(?string $current = null): array
    {
        $roles = [
            'agent' => __('Agent for'),
            'authorized' => __('Authorized for'),
        ];

        if (filled($current) && ! isset($roles[$current]) && isset(self::EARLIER_ROLES[$current])) {
            $roles[$current] = __(self::EARLIER_ROLES[$current]);
        }

        return $roles;
    }

    /** The choices before: still read on minutes recorded with them. */
    private const EARLIER_ROLES = [
        'present_for' => 'Attending for',
        'lawyer' => 'Lawyer',
        'legal_consultant' => 'Legal consultant',
        'employee' => 'Employee',
    ];

    /**
     * The capacity an attendee is listed under — whom they stand for, by
     * name and capacity: "وكيلاً عن (السادة/ المهاد للتجارة - المدعي)",
     * "مفوضاً عن (…)". Null when they stand for no one (their capacity
     * stays as typed).
     */
    public static function capacityFor(MatterMinutes $minutes, mixed $represents, ?string $as): ?string
    {
        if (blank($represents)) {
            return null;
        }

        $arabic = self::arabic($minutes);
        $candidate = collect(LetterComposer::candidates($minutes->matter, $arabic))
            ->first(fn (array $c): bool => (int) ($c['party_id'] ?? 0) === (int) $represents);

        $how = $arabic
            ? ['authorized' => 'مفوضاً عن', 'present_for' => 'حاضر عن', 'lawyer' => 'محامٍ عن', 'legal_consultant' => 'مستشار قانوني عن', 'employee' => 'موظف عن'][$as] ?? 'وكيلاً عن'
            : ['authorized' => 'Authorized for', 'present_for' => 'Attending for', 'lawyer' => 'Lawyer for', 'legal_consultant' => 'Legal consultant for', 'employee' => 'Employee for'][$as] ?? 'Agent for';

        return $candidate ? $how.' ('.self::standsForLabel($minutes, $candidate).')' : $how;
    }

    /**
     * A company, an office or an establishment — by its name — rather than
     * a person: addressed "السادة/".
     */
    public static function isCompany(string $name): bool
    {
        return (bool) preg_match(
            '/(^|[\s\-\(])(شركة|شركه|مؤسسة|مؤسسه|للمحاماة|مجموعة|مكتب|بنك|مصرف|ذ\.?\s?م\.?\s?م|ش\.?\s?م\.?\s?[عخ]|م\.?\s?م\.?\s?ح|المحدودة|القابضة|للتجارة|للمقاولات|لخدمات|LLC|L\.L\.C|FZE|FZCO|FZ-?LLC|Ltd|Limited|Company|Co\.|Inc\.?|Corp|Group|Bank|PJSC|P\.J\.S\.C|PSC|Est\.|Establishment|Trading)($|[\s\.\,\-\)])/iu',
            $name,
        );
    }

    /**
     * The ID numbers, phones and emails typed at the meeting, kept on the
     * parties for next time: the ID replaces the one known; a phone or email
     * becomes the party's latest (Party::addContact) — the attendee's own
     * party's, and the main party's they stand for. An attendee added by
     * hand counts when picked from the parties, or named as one of the
     * matter's.
     */
    public static function rememberContactDetails(MatterMinutes $minutes): void
    {
        $minutes->loadMissing('matter.matterParties.party');
        $byName = $minutes->matter?->matterParties
            ->pluck('party')->filter()
            ->keyBy(fn (Party $party) => self::nameKey((string) $party->name)) ?? collect();

        foreach ($minutes->attendees ?? [] as $attendee) {
            if (! is_array($attendee)) {
                continue;
            }

            $id = trim((string) ($attendee['id_number'] ?? ''));
            $phone = trim((string) ($attendee['phone'] ?? ''));
            $email = trim((string) ($attendee['email'] ?? ''));
            if ($id === '' && $phone === '' && $email === '') {
                continue;
            }

            // Read afresh: two lines may be the same party.
            $party = Party::find(filled($attendee['party_id'] ?? null)
                ? $attendee['party_id']
                : $byName->get(self::nameKey((string) ($attendee['name'] ?? '')))?->getKey());

            if ($party) {
                if ($id !== '' && ($party->extra['id_number'] ?? null) !== $id) {
                    $party->update(['extra' => [...((array) ($party->extra ?? [])), 'id_number' => $id]]);
                }

                $party->addContact($phone, $email);
            }

            // …and the main party they stand for.
            $main = filled($attendee['represents'] ?? null) ? Party::find($attendee['represents']) : null;
            if ($main && ! $main->is($party)) {
                $main->addContact($phone, $email);
            }
        }
    }

    /** A name compared without its spacing or case. */
    private static function nameKey(string $name): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim($name)) ?? '');
    }

    /**
     * Final: its wording kept as it is now, the time the meeting ended
     * ({{minutes.end_time}}; now, unless given), and its PDF among the
     * matter's attachments (replacing the one of an earlier finalising).
     */
    public function finalise(MatterMinutes $minutes, ?int $userId = null, ?CarbonInterface $endedAt = null): MatterMinutes
    {
        return DB::transaction(function () use ($minutes, $userId, $endedAt) {
            $minutes->loadMissing(['template', 'matter']);
            $minutes->update([
                'ended_at' => $endedAt ?? now(),
                // Kept as they read now, as the wording is.
                'opening' => self::opening($minutes),
                'closing' => self::closing($minutes),
                'body' => SignatureLayouts::freeze(LetterComposer::normalizeMergeTags((string) ($minutes->body ?: $minutes->template?->body))),
                'status' => MatterMinutes::FINAL,
                'finalized_at' => now(),
            ]);

            self::rememberContactDetails($minutes);

            $pdf = (new LetterPdf(self::composer($minutes->fresh())))->render();
            $path = 'attachments/minutes/'.$minutes->matter_id.'/'.$minutes->number.'-'.Str::random(6).'.pdf';
            Storage::disk('public')->put($path, $pdf);

            $minutes->attachment?->delete();

            $attachment = $minutes->matter->attachments()->create([
                'user_id' => $userId,
                'type' => 'minutes',
                'path' => $path,
                'name' => self::fileName($minutes).'.pdf',
                'size' => strlen($pdf),
                'extension' => 'pdf',
            ]);

            $minutes->update(['attachment_id' => $attachment->getKey()]);
            MatterProgressRecorder::meetingHeld($minutes, $userId);

            return $minutes->fresh();
        });
    }

    /**
     * "محضر 1 — 3153-2026 — 30-09-2026", safe as a file name.
     */
    public static function fileName(MatterMinutes $minutes): string
    {
        $name = trim(__('Minutes').' '.$minutes->number.' — '.str_replace('/', '-', (string) $minutes->matter?->reference)
            .($minutes->meeting_at ? ' — '.$minutes->meeting_at->format('d-m-Y') : ''));

        return trim(preg_replace(['/[\\\\\/:"*?<>|\x00-\x1F]+/u', '/\s+/u'], ' ', $name));
    }
}
