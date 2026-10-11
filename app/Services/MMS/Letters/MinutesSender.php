<?php

namespace App\Services\MMS\Letters;

use App\Mail\LetterEmail;
use App\Models\MatterEmail;
use App\Models\MatterMinutes;
use App\Models\MinutesDelivery;
use App\Models\Party;
use App\Models\WhatsAppTemplate;
use App\Services\MMS\BulkMailPlaceholders;
use App\Services\MMS\MatterProgressRecorder;
use App\Services\MMS\SenderMailer;
use App\Services\MMS\SentEmailArchive;
use App\Services\WhatsAppCloud;
use App\Services\WhatsAppService;
use App\Support\Addresses;
use App\Support\EmailGrouping;
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
     * @param  list<array{path: string, name: string}>  $attachments  more files, by email only
     * @param  list<string>  $cc  copied in on each email
     * @param  string|bool|null  $grouping  EmailGrouping: one email each, by party, or one for all
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
        array $attachments = [],
        array $cc = [],
        string|bool|null $grouping = EmailGrouping::SEPARATE,
    ): array {
        $result = ['sent' => 0, 'failed' => 0, 'errors' => []];
        $composer = MinutesService::composer($minutes);
        $values = $composer->values();
        $pdf = $this->pdf($minutes, $composer);
        $fileName = MinutesService::fileName($minutes).'.pdf';
        // Files of this send's own, with the minutes in each email (not on
        // WhatsApp: its approved template carries the minutes only).
        $files = [
            ['name' => $fileName, 'data' => $pdf, 'mime' => 'application/pdf'],
            ...LetterMailer::attachedFiles($attachments),
        ];
        $mediaId = null;
        $arabic = $composer->isArabic();
        $personal = fn (string $name): array => [...$values, ...Honorific::values($name, $arabic)];
        // Each inbox and WhatsApp number once, however many recipients share it.
        $emailed = [];
        $messaged = [];
        // Who it reached (by line), and how, for the matter's progress.
        $reachedRows = [];
        $methods = [];
        $recipients = array_values(array_filter($recipients, 'is_array'));

        // By email: each line's addresses not yet on an earlier one…
        $byEmail = [];
        foreach ($recipients as $i => $recipient) {
            $emails = Addresses::without(Addresses::emails([...(array) ($recipient['emails'] ?? []), $recipient['email'] ?? null]), $emailed);

            if (! empty($recipient['by_email']) && $emails !== [] && $senderKey) {
                $emailed = [...$emailed, ...$emails];
                $byEmail[$i] = [...$recipient, 'emails' => $emails];
            }
        }

        // …in one email each, by party, or all together.
        foreach (self::emailGroups($byEmail, EmailGrouping::from($grouping), $arabic) as $group) {
            $emails = Addresses::emails(collect($group['rows'])->flatMap(fn (array $r) => $r['emails'])->all());
            $greeting = $personal($group['name']);

            $email = new LetterEmail(
                BulkMailPlaceholders::apply((string) ($subject ?: self::defaultSubject($arabic)), array_map('strip_tags', $greeting)),
                BulkMailPlaceholders::apply((string) ($body ?: self::defaultBody($arabic)), $greeting, escape: true),
                $arabic,
                [],
                $files,
            );
            $copied = Addresses::without(Addresses::emails($cc), $emails);

            $sent = $this->attempt($minutes, array_values($group['rows']), MinutesDelivery::EMAIL, Str::limit(implode(', ', $emails), 250, ''), $userId, $result, function () use ($senderKey, $email, $emails, $copied, $minutes, $userId) {
                $message = SenderMailer::using(SenderMailer::sender($senderKey), fn () => Mail::to($emails)->cc($copied)->send($email));
                // Remembered, for its replies.
                MatterEmail::recordSent($minutes->matter_id, $minutes, $senderKey, $message?->getMessageId(), $email->emailSubject, [...$emails, ...$copied], $userId);

                return null;
            });

            if ($sent) {
                $reachedRows += array_fill_keys(array_keys($group['rows']), true);
                $methods[MatterProgressRecorder::EMAIL] = true;

                // Kept with the matter as it went — once the page has answered.
                $names = collect($group['rows'])->pluck('name')->filter()->implode('، ');
                $title = __('Minutes no. :number', ['number' => $minutes->number]);
                $sender = SenderMailer::sender($senderKey);
                if ($minutes->matter) {
                    defer(fn () => app(SentEmailArchive::class)->keepEmail($minutes->matter, $email, $sender, $names, $emails, $copied, $title, $userId));
                }
            }
        }

        // By WhatsApp: each on their own.
        foreach ($recipients as $i => $recipient) {
            $phone = WhatsAppService::formatWhatsAppNumber($recipient['phone'] ?? null);

            if (! empty($recipient['by_whatsapp']) && $template && $phone && ! in_array($phone, $messaged, true)) {
                $messaged[] = $phone;
                $greeting = $personal(trim((string) ($recipient['name'] ?? '')));

                $sent = $this->attempt($minutes, [$recipient], MinutesDelivery::WHATSAPP, $phone, $userId, $result, function () use ($template, $phone, $greeting, $pdf, $fileName, &$mediaId) {
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
                        $template->parameterValues($greeting),
                        $template->header === 'document' ? ['id' => $mediaId, 'filename' => $fileName] : null,
                    );
                });

                if ($sent) {
                    $reachedRows[$i] = true;
                    $methods[MatterProgressRecorder::WHATSAPP] = true;
                }
            }
        }

        ksort($reachedRows);
        $reached = array_values(array_map(fn (int $i): string => trim((string) ($recipients[$i]['name'] ?? '')), array_keys($reachedRows)));

        if ($reached !== []) {
            MatterProgressRecorder::minutesSent($minutes, $reached, $userId, methods: array_keys($methods));
        }

        return $result;
    }

    /**
     * One send — to one line, or to several in one email — kept for each,
     * whether it went or not.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  array{sent: int, failed: int, errors: list<string>}  $result
     * @param  callable(): ?string  $send  the message id, if any
     */
    private function attempt(MatterMinutes $minutes, array $rows, string $channel, string $address, ?int $userId, array &$result, callable $send): bool
    {
        $deliveries = array_map(fn (array $row) => $minutes->deliveries()->create([
            'party_id' => filled($row['party_id'] ?? null) ? (int) $row['party_id'] : null,
            'name' => trim((string) ($row['name'] ?? '')) ?: $address,
            'channel' => $channel,
            'address' => $address,
            'status' => MinutesDelivery::SENT,
            'sent_by' => $userId,
        ]), $rows);

        try {
            $messageId = $send();

            foreach ($deliveries as $delivery) {
                $delivery->update(['message_id' => $messageId, 'sent_at' => now()]);
                $result['sent']++;
            }

            return true;
        } catch (\Throwable $e) {
            foreach ($deliveries as $delivery) {
                $delivery->update(['status' => MinutesDelivery::FAILED, 'error' => Str::limit($e->getMessage(), 1000)]);
                $result['failed']++;
            }
            $result['errors'][] = collect($deliveries)->pluck('name')->implode('، ').': '.$e->getMessage();

            return false;
        }
    }

    /**
     * The emails to send: one a line; one a party — its own line, its
     * representatives' and theirs who attended for it (a line of no party
     * on its own); or one for all. Each named as it greets: the person, the
     * party, or the parties.
     *
     * @param  array<int, array<string, mixed>>  $rows  by their place in the list
     * @return list<array{name: string, rows: array<int, array<string, mixed>>}>
     */
    public static function emailGroups(array $rows, string $grouping, bool $arabic): array
    {
        $name = fn (array $row): string => trim((string) ($row['name'] ?? ''));

        if ($rows === []) {
            return [];
        }

        if ($grouping === EmailGrouping::ALL) {
            return [['name' => count($rows) === 1 ? $name(reset($rows)) : ($arabic ? 'السادة/ أطراف الدعوى' : 'All parties'), 'rows' => $rows]];
        }

        if ($grouping === EmailGrouping::SEPARATE) {
            return array_values(array_map(fn (array $row, int $i): array => ['name' => $name($row), 'rows' => [$i => $row]], $rows, array_keys($rows)));
        }

        $groups = [];
        foreach ($rows as $i => $row) {
            $key = filled($row['group'] ?? null) ? 'party-'.(int) $row['group'] : 'line-'.$i;
            $groups[$key][$i] = $row;
        }

        return array_values(array_map(function (array $group) use ($name): array {
            // Greeted as the party, when it's among them; else the first.
            $party = collect($group)->first(fn (array $row) => filled($row['group'] ?? null) && (int) ($row['party_id'] ?? 0) === (int) $row['group']);

            return ['name' => $name($party ?? reset($group)), 'rows' => $group];
        }, $groups));
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

        $groupOf = fn (array $a): ?int => filled($a['represents'] ?? null)
            ? ($mainOf[(int) $a['represents']] ?? (int) $a['represents'])
            : ($mainOf[(int) ($a['party_id'] ?? 0)] ?? null);

        return $rows
            ->map(function (array $a) use ($parties, $groupOf, &$emailed, &$messaged): array {
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
                    // The main party it goes with, sent by party.
                    'group' => $groupOf($a),
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
