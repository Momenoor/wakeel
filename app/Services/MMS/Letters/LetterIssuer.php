<?php

namespace App\Services\MMS\Letters;

use App\Enums\LetterStatus;
use App\Models\Letterhead;
use App\Models\LetterItem;
use App\Models\LetterTemplate;
use App\Models\Matter;
use App\Models\MatterLetter;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Issues a letter on a matter: gives it the matter's next reference
 * number (JPA/{year}/{number}/{sequence}) and freezes everything it is
 * made of — the template's wording, the recipients, the inputs (ticked
 * library items stored as their text) — so editing a template or the item
 * library later never changes a letter already issued.
 */
class LetterIssuer
{
    /**
     * @param  list<array{name: string, role: ?string, emails: list<string>, party_id?: int|null}>  $recipients
     * @param  array<string, mixed>  $inputs
     */
    public function issue(
        LetterTemplate $template,
        Matter $matter,
        array $recipients,
        array $inputs,
        ?CarbonInterface $date = null,
        ?Letterhead $letterhead = null,
        ?int $userId = null,
        ?string $attention = null,
    ): MatterLetter {
        $inputs = $this->freezeItems($template, $inputs);
        $letterhead ??= $template->letterhead ?? Letterhead::default();
        $date ??= now();

        return DB::transaction(function () use ($template, $matter, $recipients, $inputs, $date, $letterhead, $userId, $attention) {
            // Locked, so two letters issued at once can't share a number.
            $sequence = (int) MatterLetter::query()->where('matter_id', $matter->getKey())->lockForUpdate()->max('sequence') + 1;
            $reference = MatterLetter::referenceFor($matter, $sequence);

            $composer = new LetterComposer($template, $matter, $inputs, $recipients, $reference, $date, $letterhead, $attention);

            $letter = MatterLetter::create([
                'letter_template_id' => $template->getKey(),
                'matter_id' => $matter->getKey(),
                'reference' => $reference,
                'sequence' => $sequence,
                'letterhead_id' => $letterhead?->getKey(),
                'sent_by' => $userId,
                'subject' => $composer->subject(),
                'attention' => filled($attention) ? trim($attention) : null,
                'body' => (string) $template->body,
                'inputs' => $inputs,
                'rendered_html' => $composer->bodyHtml(),
                'letter_date' => $date,
                'status' => LetterStatus::DRAFT,
            ]);

            foreach ($recipients as $recipient) {
                $letter->recipients()->create([
                    'recipient_id' => $recipient['party_id'] ?? null,
                    'name' => $recipient['name'],
                    'role' => $recipient['role'] ?? null,
                    'email' => $recipient['emails'][0] ?? null,
                    'emails' => array_values($recipient['emails'] ?? []),
                    'delivery_status' => LetterStatus::DRAFT,
                ]);
            }

            return $letter;
        });
    }

    /**
     * The letter rebuilt exactly as issued, to render again as PDF or Word.
     */
    public static function composerFor(MatterLetter $letter): LetterComposer
    {
        $letter->loadMissing(['template', 'matter', 'letterhead', 'recipients']);

        // The wording as issued, not the template's current one.
        $template = ($letter->template ?? new LetterTemplate(['locale' => 'ar']))->replicate();
        $template->body = (string) $letter->body;

        return new LetterComposer(
            $template,
            $letter->matter,
            $letter->inputs ?? [],
            $letter->recipients->map(fn ($recipient) => [
                'name' => (string) $recipient->name,
                'role' => $recipient->role,
                'emails' => $recipient->emails ?? array_filter([$recipient->email]),
                'party_id' => $recipient->recipient_id,
            ])->all(),
            $letter->reference,
            $letter->letter_date ?? $letter->created_at,
            $letter->letterhead,
            $letter->attention,
        );
    }

    /**
     * Change an issued letter — its date, letterhead, attention line and
     * recipients — keeping its reference and its wording as issued; the
     * letter's text is rendered again from them.
     *
     * @param  list<array{name: string, role: ?string, emails: list<string>, party_id?: int|null}>  $recipients
     */
    public function revise(MatterLetter $letter, array $recipients, CarbonInterface $date, ?Letterhead $letterhead, ?string $attention): MatterLetter
    {
        return DB::transaction(function () use ($letter, $recipients, $date, $letterhead, $attention) {
            $letter->update([
                'letter_date' => $date,
                'letterhead_id' => $letterhead?->getKey() ?? $letter->letterhead_id,
                'attention' => filled($attention) ? trim($attention) : null,
            ]);

            $letter->recipients()->delete();

            foreach ($recipients as $recipient) {
                $letter->recipients()->create([
                    'recipient_id' => $recipient['party_id'] ?? null,
                    'name' => $recipient['name'],
                    'role' => $recipient['role'] ?? null,
                    'email' => $recipient['emails'][0] ?? null,
                    'emails' => array_values($recipient['emails'] ?? []),
                    'delivery_status' => LetterStatus::DRAFT,
                ]);
            }

            $letter = $letter->fresh(['template', 'matter', 'letterhead', 'recipients']);
            $letter->update(['rendered_html' => self::composerFor($letter)->bodyHtml()]);

            return $letter;
        });
    }

    /**
     * "JPA-2026-986-3 subject", safe as a file name on any system, at
     * most 80 characters.
     */
    public static function fileName(MatterLetter $letter): string
    {
        $name = trim(str_replace('/', '-', (string) $letter->reference).' '.$letter->subject);
        $name = trim(preg_replace(['/[\\\\\/:"*?<>|\x00-\x1F]+/u', '/\s+/u'], ' ', $name));

        return rtrim(mb_substr($name, 0, 80)) ?: 'letter';
    }

    /**
     * Ticked library items stored as their text, in the order ticked.
     *
     * @param  array<string, mixed>  $inputs
     * @return array<string, mixed>
     */
    private function freezeItems(LetterTemplate $template, array $inputs): array
    {
        foreach ($template->inputs ?? [] as $input) {
            if (($input['type'] ?? null) !== 'items') {
                continue;
            }

            $key = $input['key'];
            $chosen = array_values((array) ($inputs[$key] ?? []));
            $texts = LetterItem::query()->whereIn('id', array_filter($chosen, 'is_numeric'))->pluck('text', 'id');

            $inputs[$key] = array_values(array_filter(array_map(
                fn ($entry) => is_numeric($entry) ? $texts[$entry] ?? null : trim((string) $entry),
                $chosen,
            ), 'filled'));
        }

        return $inputs;
    }
}
