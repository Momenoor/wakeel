<?php

namespace App\Services\MMS\Letters;

use App\Models\Letterhead;
use App\Models\LetterTemplate;
use App\Models\MatterMinutes;
use App\Models\Party;
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
                'party_id' => filled($a['party_id'] ?? null) ? (int) $a['party_id'] : null,
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
        return preg_replace('/<img\b[^>]*>/i', '', self::composer($minutes)->bodyHtml()) ?? '';
    }

    /**
     * Who may attend: the matter's parties and their representatives, each
     * not yet marked present, with the phone and ID number known for them.
     *
     * @return list<array{present: bool, title: string, name: string, capacity: ?string, id_number: ?string, phone: ?string, party_id: ?int}>
     */
    public static function attendeeCandidates(MatterMinutes $minutes): array
    {
        $arabic = (($minutes->template?->locale) ?: 'ar') !== 'en';
        $parties = Party::query()->whereIn('id', collect(LetterComposer::candidates($minutes->matter, $arabic))->pluck('party_id')->filter())->get()->keyBy('id');

        return collect(LetterComposer::candidates($minutes->matter, $arabic))
            ->map(fn (array $c): array => [
                'present' => false,
                'title' => self::isCompany($c['name']) ? ($arabic ? 'السادة/' : 'Messrs.') : ($arabic ? 'الأستاذ/' : 'Mr.'),
                'name' => $c['name'],
                'capacity' => $c['role'],
                'id_number' => $parties->get($c['party_id'])?->extra['id_number'] ?? null,
                'phone' => $c['phones'][0] ?? null,
                'party_id' => $c['party_id'],
            ])
            ->values()
            ->all();
    }

    /**
     * A company, an office or an establishment — by its name — rather than
     * a person: addressed "السادة/".
     */
    public static function isCompany(string $name): bool
    {
        return (bool) preg_match(
            '/(^|[\s\-\(])(شركة|شركه|مؤسسة|مؤسسه|مجموعة|مكتب|بنك|مصرف|ذ\.?\s?م\.?\s?م|ش\.?\s?م\.?\s?[عخ]|م\.?\s?م\.?\s?ح|المحدودة|القابضة|للتجارة|للمقاولات|لخدمات|LLC|L\.L\.C|FZE|FZCO|FZ-?LLC|Ltd|Limited|Company|Co\.|Inc\.?|Corp|Group|Bank|PJSC|P\.J\.S\.C|PSC|Est\.|Establishment|Trading)($|[\s\.\,\-\)])/iu',
            $name,
        );
    }

    /**
     * ID numbers typed at the meeting, kept on the parties for next time.
     */
    public static function rememberIdNumbers(MatterMinutes $minutes): void
    {
        foreach ($minutes->attendees ?? [] as $attendee) {
            if (blank($attendee['party_id'] ?? null) || blank($attendee['id_number'] ?? null)) {
                continue;
            }

            $party = Party::find($attendee['party_id']);
            if ($party && ($party->extra['id_number'] ?? null) !== $attendee['id_number']) {
                $party->update(['extra' => [...((array) ($party->extra ?? [])), 'id_number' => trim((string) $attendee['id_number'])]]);
            }
        }
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
