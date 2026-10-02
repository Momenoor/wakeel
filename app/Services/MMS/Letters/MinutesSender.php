<?php

namespace App\Services\MMS\Letters;

use App\Mail\LetterEmail;
use App\Models\MatterMinutes;
use App\Models\MinutesDelivery;
use App\Models\Party;
use App\Models\WhatsAppTemplate;
use App\Services\MMS\BulkMailPlaceholders;
use App\Services\MMS\SenderMailer;
use App\Services\WhatsAppCloud;
use App\Services\WhatsAppService;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Finalised minutes, sent to the attendees to sign and send back: each by
 * email and/or WhatsApp, the PDF attached. Every send is kept (a
 * MinutesDelivery) — a WhatsApp reply with the signed copy is matched to
 * it (see MinutesSignedCopies).
 */
class MinutesSender
{
    public function __construct(private readonly WhatsAppCloud $whatsapp) {}

    /**
     * @param  list<array{name?: string, party_id?: ?int, email?: ?string, phone?: ?string, by_email?: bool, by_whatsapp?: bool}>  $recipients
     * @return array{sent: int, failed: int, errors: list<string>}
     */
    public function send(
        MatterMinutes $minutes,
        array $recipients,
        ?string $senderKey = null,
        ?string $subject = null,
        ?string $body = null,
        ?WhatsAppTemplate $template = null,
        ?int $userId = null,
    ): array {
        $result = ['sent' => 0, 'failed' => 0, 'errors' => []];
        $composer = MinutesService::composer($minutes);
        $values = $composer->values();
        $pdf = $this->pdf($minutes, $composer);
        $fileName = MinutesService::fileName($minutes).'.pdf';
        $mediaId = null;

        foreach ($recipients as $recipient) {
            $name = trim((string) ($recipient['name'] ?? ''));
            $personal = [...$values, 'recipient.name' => $name];

            if (! empty($recipient['by_email']) && filled($recipient['email'] ?? null) && $senderKey) {
                $this->attempt($minutes, $recipient, MinutesDelivery::EMAIL, trim((string) $recipient['email']), $userId, $result, function () use ($senderKey, $subject, $body, $personal, $composer, $pdf, $fileName, $recipient) {
                    $email = new LetterEmail(
                        BulkMailPlaceholders::apply((string) ($subject ?: self::defaultSubject($composer->isArabic())), array_map('strip_tags', $personal)),
                        BulkMailPlaceholders::apply((string) ($body ?: self::defaultBody($composer->isArabic())), $personal, escape: true),
                        $composer->isArabic(),
                        [],
                        [['name' => $fileName, 'data' => $pdf, 'mime' => 'application/pdf']],
                    );

                    SenderMailer::using(SenderMailer::sender($senderKey), fn () => Mail::to(trim((string) $recipient['email']))->send($email));

                    return null;
                });
            }

            if (! empty($recipient['by_whatsapp']) && $template && ($phone = WhatsAppService::formatWhatsAppNumber($recipient['phone'] ?? null))) {
                $this->attempt($minutes, $recipient, MinutesDelivery::WHATSAPP, $phone, $userId, $result, function () use ($template, $phone, $personal, $pdf, $fileName, &$mediaId) {
                    if ($template->header === 'document' && $mediaId === null) {
                        $file = tempnam(sys_get_temp_dir(), 'minutes');
                        file_put_contents($file, $pdf);
                        try {
                            $mediaId = $this->whatsapp->upload($file, 'application/pdf', $fileName);
                        } finally {
                            @unlink($file);
                        }
                    }

                    return $this->whatsapp->sendTemplate(
                        $phone,
                        $template->meta_name,
                        $template->language,
                        $template->parameterValues($personal),
                        $template->header === 'document' ? ['id' => $mediaId, 'filename' => $fileName] : null,
                    );
                });
            }
        }

        return $result;
    }

    /**
     * One send, kept whether it went or not.
     *
     * @param  array<string, mixed>  $recipient
     * @param  array{sent: int, failed: int, errors: list<string>}  $result
     * @param  callable(): ?string  $send  the message id, if any
     */
    private function attempt(MatterMinutes $minutes, array $recipient, string $channel, string $address, ?int $userId, array &$result, callable $send): void
    {
        $delivery = $minutes->deliveries()->create([
            'party_id' => filled($recipient['party_id'] ?? null) ? (int) $recipient['party_id'] : null,
            'name' => trim((string) ($recipient['name'] ?? '')) ?: $address,
            'channel' => $channel,
            'address' => $address,
            'status' => MinutesDelivery::SENT,
            'sent_by' => $userId,
        ]);

        try {
            $delivery->update(['message_id' => $send(), 'sent_at' => now()]);
            $result['sent']++;
        } catch (\Throwable $e) {
            $delivery->update(['status' => MinutesDelivery::FAILED, 'error' => Str::limit($e->getMessage(), 1000)]);
            $result['failed']++;
            $result['errors'][] = $delivery->name.': '.$e->getMessage();
        }
    }

    /**
     * The PDF filed when the minutes were finalised; made afresh when it
     * isn't there.
     */
    private function pdf(MatterMinutes $minutes, LetterComposer $composer): string
    {
        $path = $minutes->attachment?->path;

        return $path && Storage::disk('public')->exists($path)
            ? (string) Storage::disk('public')->get($path)
            : (new LetterPdf($composer))->render();
    }

    /**
     * Who to send to: the attendees marked present, with their email (from
     * their party) and phone.
     *
     * @return list<array{name: string, party_id: ?int, email: ?string, phone: ?string, by_email: bool, by_whatsapp: bool}>
     */
    public static function recipients(MatterMinutes $minutes): array
    {
        $parties = Party::query()->whereIn('id', collect($minutes->attendees ?? [])->pluck('party_id')->filter())->get()->keyBy('id');

        return collect($minutes->attendees ?? [])
            ->filter(fn ($a) => is_array($a) && ! empty($a['present']) && filled($a['name'] ?? null))
            ->map(function (array $a) use ($parties): array {
                $party = filled($a['party_id'] ?? null) ? $parties->get($a['party_id']) : null;
                $email = collect((array) ($party?->email ?? []))->filter()->first();
                $phone = $a['phone'] ?? collect((array) ($party?->phone ?? []))->filter()->first();

                return [
                    'name' => trim(trim((string) ($a['title'] ?? '')).' '.trim((string) $a['name'])),
                    'party_id' => $party?->getKey(),
                    'email' => $email,
                    'phone' => $phone,
                    'by_email' => filled($email),
                    'by_whatsapp' => filled($phone),
                ];
            })
            ->values()
            ->all();
    }

    public static function defaultSubject(bool $arabic): string
    {
        return $arabic
            ? 'محضر اجتماع الخبرة رقم ({{minutes.number}}) — الدعوى رقم {{matter.reference}} — للتوقيع'
            : 'Minutes No. {{minutes.number}} — {{matter.reference}} — for signature';
    }

    public static function defaultBody(bool $arabic): string
    {
        return $arabic
            ? '<p>السادة/ {{recipient.name}} المحترمين،</p><p>تحية طيبة وبعد،</p>'
                .'<p>نرفق لكم محضر اجتماع الخبرة رقم ({{minutes.number}}) في الدعوى رقم {{matter.reference}}، المنعقد بتاريخ {{meeting.date}}.</p>'
                .'<p>نرجو التكرم بمراجعة المحضر وتوقيعه، ثم إعادة إرساله إلينا موقّعاً.</p><p>مع خالص الشكر والتقدير.</p>'
            : '<p>Dear {{recipient.name}},</p>'
                .'<p>Please find attached the minutes No. {{minutes.number}} of the expert meeting in case {{matter.reference}}, held on {{meeting.date}}.</p>'
                .'<p>Kindly review and sign them, and send them back to us signed.</p><p>Kind regards.</p>';
    }
}
