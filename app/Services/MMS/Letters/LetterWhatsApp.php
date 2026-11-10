<?php

namespace App\Services\MMS\Letters;

use App\Enums\LetterSendChannel;
use App\Enums\LetterStatus;
use App\Models\MatterLetter;
use App\Models\MatterLetterRecipient;
use App\Models\User;
use App\Models\WhatsAppTemplate;
use App\Services\MMS\MatterProgressRecorder;
use App\Services\WhatsAppCloud;
use App\Services\WhatsAppService;
use App\Support\RequestMemo;
use Illuminate\Support\Str;

/**
 * A letter sent to its recipients on WhatsApp from the system: by the approved template "A letter to its
 * recipients" (Templates → WhatsApp templates), the letter's PDF in its header, to each number chosen — once
 * a number. A letter that reached someone is sent (as by email): its recipients marked, a step on the matter's
 * progress, and WhatsApp ticked on what was asked of the administrator (LetterSendRequests).
 */
class LetterWhatsApp
{
    public function __construct(private readonly WhatsAppCloud $whatsapp) {}

    /** The template letters go by — none: nothing to send with. Read once a request. */
    public static function template(): ?WhatsAppTemplate
    {
        return RequestMemo::remember('letter-whatsapp.template', fn (): ?WhatsAppTemplate => WhatsAppTemplate::default(WhatsAppTemplate::LETTER));
    }

    public static function available(): bool
    {
        return WhatsAppCloud::configured() && self::template() !== null;
    }

    /**
     * The numbers a letter can go to: each recipient's, once a number.
     *
     * @return array<string, string> the number as WhatsApp takes it => "Name — number"
     */
    public static function numbers(MatterLetter $letter): array
    {
        $numbers = [];

        foreach ($letter->recipients as $recipient) {
            foreach (array_filter((array) $recipient->phones) as $phone) {
                $to = WhatsAppService::formatWhatsAppNumber($phone);

                if ($to !== null && ! isset($numbers[$to])) {
                    $numbers[$to] = trim($recipient->name.' — '.$phone, ' —');
                }
            }
        }

        return $numbers;
    }

    /**
     * The template's parameters can use: the letter's own placeholders (text only) and {{recipient}}.
     *
     * @return array<string, string>
     */
    public static function values(MatterLetter $letter, string $recipient = ''): array
    {
        $values = collect(LetterIssuer::composerFor($letter)->values())
            ->filter(fn ($value): bool => is_scalar($value))
            ->map(fn ($value): string => Str::limit(trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8'))), 300, '…'))
            ->all();

        return [...$values, 'reference' => (string) $letter->reference, 'subject' => (string) $letter->subject, 'recipient' => $recipient];
    }

    /**
     * @param  list<string>  $numbers  of numbers(), those to send to
     * @return array{sent: int, failed: int, errors: list<string>}
     */
    public function send(MatterLetter $letter, array $numbers, ?User $by): array
    {
        $result = ['sent' => 0, 'failed' => 0, 'errors' => []];
        $template = self::template();
        $chosen = array_intersect_key(self::numbers($letter), array_flip(array_map('strval', $numbers)));

        if ($template === null || ! WhatsAppCloud::configured() || $chosen === []) {
            return $result;
        }

        $composer = LetterIssuer::composerFor($letter);
        $fileName = LetterIssuer::fileName($letter).'.pdf';
        $mediaId = null;
        // Whose number each is, to mark and to name.
        $owners = [];
        foreach ($letter->recipients as $recipient) {
            foreach (array_filter((array) $recipient->phones) as $phone) {
                $owners[WhatsAppService::formatWhatsAppNumber($phone) ?? ''] ??= $recipient;
            }
        }
        $reached = [];

        foreach (array_keys($chosen) as $to) {
            $to = (string) $to;
            $recipient = $owners[$to] ?? null;

            try {
                if ($template->header === 'document' && $mediaId === null) {
                    $mediaId = $this->whatsapp->upload((new LetterPdf($composer))->render(), 'application/pdf', $fileName);
                }

                $this->whatsapp->sendTemplate(
                    $to,
                    $template->meta_name,
                    $template->language,
                    $template->parameterValues(self::values($letter, (string) $recipient?->name)),
                    $template->header === 'document' ? ['id' => $mediaId, 'filename' => $fileName] : null,
                );

                $result['sent']++;
                if ($recipient instanceof MatterLetterRecipient) {
                    $reached[$recipient->getKey()] = $recipient;
                }
            } catch (\Throwable $e) {
                $result['failed']++;
                $result['errors'][] = $chosen[$to].': '.$e->getMessage();
            }
        }

        if ($result['sent'] === 0) {
            return $result;
        }

        foreach ($reached as $recipient) {
            if ($recipient->delivery_status !== LetterStatus::SENT) {
                $recipient->update(['delivery_status' => LetterStatus::SENT, 'delivered_at' => now(), 'failure_reason' => null]);
            }
        }

        $letter->update(['status' => LetterStatus::SENT, 'sent_at' => now()]);
        MatterProgressRecorder::letterSent($letter, array_values(array_map(fn (MatterLetterRecipient $r): string => (string) $r->name, $reached)), $by?->getKey(), methods: [MatterProgressRecorder::WHATSAPP]);

        // Asked of the administrator to send by WhatsApp: done.
        app(LetterSendRequests::class)->done($letter, LetterSendChannel::WHATSAPP, $by);

        return $result;
    }
}
