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
use App\Support\Addresses;
use App\Support\Honorific;
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
     * @param  list<array{name?: string, party_id?: ?int, emails?: list<string>, email?: ?string, phone?: ?string, by_email?: bool, by_whatsapp?: bool}>  $recipients
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
        // Each inbox and WhatsApp number once, however many recipients share it.
        $emailed = [];
        $messaged = [];

        foreach ($recipients as $recipient) {
            $name = trim((string) ($recipient['name'] ?? ''));
            // "الأستاذة/ موزة …": the bare name, its title and the honorific that agrees.
            $personal = [...$values, ...Honorific::values($name, $composer->isArabic())];

            $emails = Addresses::without(Addresses::emails([...(array) ($recipient['emails'] ?? []), $recipient['email'] ?? null]), $emailed);

            if (! empty($recipient['by_email']) && $emails !== [] && $senderKey) {
                $emailed = [...$emailed, ...$emails];
                $this->attempt($minutes, $recipient, MinutesDelivery::EMAIL, Str::limit(implode(', ', $emails), 250, ''), $userId, $result, function () use ($senderKey, $subject, $body, $personal, $composer, $pdf, $fileName, $emails) {
                    $email = new LetterEmail(
                        BulkMailPlaceholders::apply((string) ($subject ?: self::defaultSubject($composer->isArabic())), array_map('strip_tags', $personal)),
                        BulkMailPlaceholders::apply((string) ($body ?: self::defaultBody($composer->isArabic())), $personal, escape: true),
                        $composer->isArabic(),
                        [],
                        [['name' => $fileName, 'data' => $pdf, 'mime' => 'application/pdf']],
                    );

                    SenderMailer::using(SenderMailer::sender($senderKey), fn () => Mail::to($emails)->send($email));

                    return null;
                });
            }

            $phone = WhatsAppService::formatWhatsAppNumber($recipient['phone'] ?? null);

            if (! empty($recipient['by_whatsapp']) && $template && $phone && ! in_array($phone, $messaged, true)) {
                $messaged[] = $phone;
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
     * Who to send to: the attendees marked present — and, for every main
     * party someone attended for (itself, its lawyer, an agent or an
     * employee), that party and all its representatives too, present or
     * not. Each with all their emails (the one typed at the meeting first)
     * and their phone (typed, else their party's latest) — an inbox or a
     * number on an earlier line isn't repeated.
     *
     * @return list<array{name: string, party_id: ?int, email: ?string, phone: ?string, by_email: bool, by_whatsapp: bool}>
     */
    public static function recipients(MatterMinutes $minutes): array
    {
        $arabic = (($minutes->template?->locale) ?: 'ar') !== 'en';
        $candidates = LetterComposer::candidates($minutes->matter, $arabic);
        $present = collect($minutes->attendees ?? [])
            ->filter(fn ($a) => is_array($a) && ! empty($a['present']) && filled($a['name'] ?? null))
            ->values();

        // The main parties attended for.
        $mainOf = [];
        foreach ($candidates as $c) {
            if (filled($c['party_id'] ?? null)) {
                $mainOf[(int) $c['party_id']] ??= filled($c['of'] ?? null) ? (int) ($candidates[$c['of']]['party_id'] ?? 0) : (int) $c['party_id'];
            }
        }

        $attendedFor = $present
            ->map(fn (array $a): ?int => filled($a['represents'] ?? null)
                ? ($mainOf[(int) $a['represents']] ?? (int) $a['represents'])
                : ($mainOf[(int) ($a['party_id'] ?? 0)] ?? null))
            ->filter()
            ->unique()
            ->all();

        // …with everyone of theirs who wasn't there.
        $sentTo = $present->pluck('party_id')->filter()->map(fn ($id) => (int) $id)->all();
        $absent = collect($candidates)
            ->filter(fn (array $c): bool => filled($c['party_id'] ?? null)
                && in_array($mainOf[(int) $c['party_id']] ?? null, $attendedFor, true)
                && ! in_array((int) $c['party_id'], $sentTo, true))
            ->unique('party_id')
            ->map(fn (array $c): array => [
                'title' => MinutesService::isCompany((string) $c['name']) ? ($arabic ? 'السادة/' : 'Messrs.') : ($arabic ? 'الأستاذ/' : 'Mr.'),
                'name' => $c['name'],
                'party_id' => $c['party_id'],
            ]);

        $rows = $present->concat($absent->values());
        $parties = Party::query()->whereIn('id', $rows->pluck('party_id')->filter())->get()->keyBy('id');

        // Every email of theirs (the one typed at the meeting first); an
        // inbox or a WhatsApp number already on an earlier line isn't
        // repeated — a party and its lawyer sharing one get it once.
        $emailed = [];
        $messaged = [];

        return $rows
            ->map(function (array $a) use ($parties, &$emailed, &$messaged): array {
                $party = filled($a['party_id'] ?? null) ? $parties->get($a['party_id']) : null;
                $emails = Addresses::without(Addresses::emails([$a['email'] ?? null, ...array_reverse(Addresses::emails($party?->email ?? []))]), $emailed);
                $emailed = [...$emailed, ...$emails];

                $phone = filled($a['phone'] ?? null) ? trim((string) $a['phone']) : $party?->latestPhone();
                if (filled($phone) && in_array(Addresses::phoneKey($phone), $messaged, true)) {
                    $phone = null;
                }
                if (filled($phone)) {
                    $messaged[] = Addresses::phoneKey($phone);
                }

                return [
                    'name' => trim(trim((string) ($a['title'] ?? '')).' '.trim((string) $a['name'])),
                    'party_id' => $party?->getKey(),
                    'emails' => $emails,
                    'phone' => $phone,
                    'by_email' => $emails !== [],
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
            ? '<p>{{recipient.salutation}}،</p><p>تحية طيبة وبعد،</p>'
                .'<p>نرفق لكم محضر اجتماع الخبرة رقم ({{minutes.number}}) في الدعوى رقم {{matter.reference}}، المنعقد بتاريخ {{meeting.date}}.</p>'
                .'<p>نرجو التكرم بمراجعة المحضر وتوقيعه، ثم إعادة إرساله إلينا موقّعاً.</p><p>مع خالص الشكر والتقدير.</p>'
            : '<p>Dear {{recipient.name}},</p>'
                .'<p>Please find attached the minutes No. {{minutes.number}} of the expert meeting in case {{matter.reference}}, held on {{meeting.date}}.</p>'
                .'<p>Kindly review and sign them, and send them back to us signed.</p><p>Kind regards.</p>';
    }
}
