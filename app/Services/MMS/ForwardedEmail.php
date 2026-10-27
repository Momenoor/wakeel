<?php

namespace App\Services\MMS;

use App\Services\MMS\Calendar\MatterReferenceMatcher;
use App\Support\EmailText;
use App\Support\Tolerant;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Closure;
use Throwable;
use Webklex\PHPIMAP\Address;
use Webklex\PHPIMAP\Message;

/**
 * An email forwarded to the filing mailbox, read: the matter the forwarder named, and the original email — as it
 * came, when it was forwarded as an attachment (the email itself inside, its Message-ID and files with it), else
 * as quoted below the forwarder's words (Outlook's From / Sent / To / Subject, in English or Arabic, or Gmail's
 * "Forwarded message"): its sender, time, subject, text, and the files the forward carried.
 */
class ForwardedEmail
{
    /** The quoted header's lines, by what each holds — Outlook and Gmail, English and Arabic. */
    private const LABELS = [
        'from' => ['From', 'من'],
        'at' => ['Sent', 'Date', 'تاريخ الإرسال', 'التاريخ', 'أرسلت', 'أُرسلت', 'مرسل', 'تم الإرسال'],
        'to' => ['To', 'إلى', 'الى'],
        'cc' => ['Cc', 'نسخة', 'نسخة إلى'],
        'subject' => ['Subject', 'الموضوع'],
    ];

    /** A forward's own subject marks: "FW:", "Fwd:", "إعادة توجيه:", a reply's "RE:" / "رد:". */
    private const SUBJECT_MARKS = '/^\s*(?:(?:fw|fwd|re|tr|إعادة توجيه|إعادة التوجيه|رد|توجيه)\s*:\s*)+/iu';

    private const ARABIC_MONTHS = [
        'يناير' => 'January', 'فبراير' => 'February', 'مارس' => 'March', 'أبريل' => 'April', 'ابريل' => 'April', 'مايو' => 'May',
        'يونيو' => 'June', 'يوليو' => 'July', 'أغسطس' => 'August', 'اغسطس' => 'August', 'سبتمبر' => 'September', 'أكتوبر' => 'October',
        'اكتوبر' => 'October', 'نوفمبر' => 'November', 'ديسمبر' => 'December',
        'كانون الثاني' => 'January', 'شباط' => 'February', 'آذار' => 'March', 'نيسان' => 'April', 'أيار' => 'May', 'حزيران' => 'June',
        'تموز' => 'July', 'آب' => 'August', 'أيلول' => 'September', 'تشرين الأول' => 'October', 'تشرين الثاني' => 'November', 'كانون الأول' => 'December',
    ];

    /**
     * The matters the forwarder named: in the address it was sent to (name+1200-2026@…), else in their own
     * words — the forward's subject and the note above the quoted email, never the original's own text.
     *
     * @param  array<string, mixed>  $message  as the collector reads one
     * @return list<int>
     */
    public static function matterIds(array $message, string $mailbox, ?array $original = null): array
    {
        foreach (self::written($message, $mailbox, $original) as $text) {
            if (($ids = MatterReferenceMatcher::matterIds($text)) !== []) {
                return $ids;
            }
        }

        return [];
    }

    /**
     * The matter numbers written where matterIds() looks — "571/2009" — whether a matter has them or not: what the
     * forwarder is told was read when none is a matter.
     *
     * @param  array<string, mixed>  $message
     * @return list<string>
     */
    public static function numbersWritten(array $message, string $mailbox, ?array $original = null): array
    {
        return collect(self::written($message, $mailbox, $original))
            ->flatMap(fn (string $text): array => MatterReferenceMatcher::references($text))
            ->map(fn (array $reference): string => $reference['number'].'/'.$reference['year'])
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Where the forwarder writes the matter, in the order looked at: the address ("files+1200-2026@jpa.ae" sent to
     * the filing mailbox "files@jpa.ae"), their note above the quoted email, the forward's subject less the
     * original's own (which may carry another matter's number).
     *
     * @param  array<string, mixed>  $message
     * @return list<string>
     */
    private static function written(array $message, string $mailbox, ?array $original): array
    {
        [$local, $domain] = array_pad(explode('@', mb_strtolower(trim($mailbox)), 2), 2, '');
        $texts = [];

        foreach ([...(array) ($message['to'] ?? []), ...(array) ($message['cc'] ?? [])] as $address) {
            if (preg_match('/^'.preg_quote($local, '/').'\+([^@]+)@'.preg_quote($domain, '/').'$/i', trim((string) $address), $m)) {
                // "1200-2026", "1200_2026", "1200.2026": the matter's number and year.
                $texts[] = (string) preg_replace('/[-_.]/', '/', $m[1]);
            }
        }

        $texts[] = self::note((string) ($message['body'] ?? ''));

        $subject = preg_replace(self::SUBJECT_MARKS, '', (string) ($message['subject'] ?? '')) ?? '';
        $originalSubject = trim((string) ($original['subject'] ?? ''));
        if ($originalSubject !== '') {
            $subject = str_replace(preg_replace(self::SUBJECT_MARKS, '', $originalSubject) ?? $originalSubject, ' ', $subject);
        }
        $texts[] = $subject;

        return $texts;
    }

    /**
     * The original email, as the collector reads one — or null when the forward carries none that can be read.
     *
     * @param  array<string, mixed>  $message
     * @return array<string, mixed>|null
     */
    public static function original(array $message): ?array
    {
        $raw = ($message['raw'] ?? null) instanceof Closure ? (string) rescue(fn () => ($message['raw'])(), '', report: false) : '';
        $attached = $raw !== '' ? self::attachedEmail($raw) : null;

        if ($attached !== null && ($original = self::fromRaw($attached)) !== null) {
            return $original;
        }

        return self::quoted($message);
    }

    /**
     * The email forwarded as an attachment (message/rfc822), found in the forward's MIME however deep.
     */
    public static function attachedEmail(string $raw): ?string
    {
        $raw = str_replace("\r\n", "\n", $raw);
        [$header, $body] = array_pad(explode("\n\n", $raw, 2), 2, '');
        $type = self::headerValue($header, 'content-type');

        if (preg_match('#^message/rfc822#i', $type)) {
            return self::decoded($body, self::headerValue($header, 'content-transfer-encoding'));
        }

        if (! preg_match('#^multipart/#i', $type) || ! preg_match('/boundary="?([^";]+)"?/i', $type, $m)) {
            return null;
        }

        foreach (array_slice(explode('--'.$m[1], $body), 1) as $part) {
            if (str_starts_with($part, '--')) {
                break;
            }

            if (($found = self::attachedEmail(ltrim($part, "\n"))) !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * A whole email (RFC 822) as the collector reads one: who, when, its text, its files, and itself (.eml).
     *
     * @return array<string, mixed>|null
     */
    public static function fromRaw(string $raw): ?array
    {
        try {
            $email = Tolerant::run(fn () => Message::fromString($raw));
        } catch (Throwable) {
            return null;
        }

        $from = collect($email->get('from')?->all() ?? [])->first(fn ($a) => $a instanceof Address);
        $addresses = fn (string $header): array => array_values(array_map(fn (Address $a): string => $a->mail, array_filter($email->get($header)?->all() ?? [], fn ($a) => $a instanceof Address)));
        $html = $email->hasHTMLBody() ? (string) $email->getHTMLBody() : '';
        $files = [];

        foreach ($email->getAttachments() as $attachment) {
            if (MatterReplyCollector::isPartOfTextPart($attachment, $html)) {
                continue;
            }

            $files[] = [
                'name' => SentMailImporter::decodeHeader((string) ($attachment->name ?: $attachment->filename)) ?: 'attachment',
                'contents' => (string) $attachment->content,
                'mime' => (string) ($attachment->content_type ?: 'application/octet-stream'),
            ];
        }

        $date = rescue(fn () => $email->getDate()->toDate(), null, report: false);

        return [
            'message_id' => (string) $email->getMessageId(),
            'subject' => SentMailImporter::decodeHeader((string) $email->getSubject()),
            'from' => (string) ($from?->mail ?? ''),
            'from_name' => SentMailImporter::decodeHeader((string) ($from?->personal ?? '')),
            'to' => $addresses('to'),
            'cc' => $addresses('cc'),
            'at' => $date !== null && $date->year >= 1971 ? $date->toIso8601String() : null,
            'body' => $html !== '' ? $html : nl2br(e((string) $email->getTextBody())),
            'attachments' => $files,
            'raw' => fn (): string => $raw,
            'exact' => true,
        ];
    }

    /**
     * The original as quoted below the forwarder's words: the header's sender, time, recipients and subject, the
     * text after it, and the files the forward carried (Outlook forwards the original's own). Null when no
     * quoted header is found.
     *
     * @param  array<string, mixed>  $message
     * @return array<string, mixed>|null
     */
    public static function quoted(array $message): ?array
    {
        $html = (string) ($message['body'] ?? '');
        $block = self::headerBlock($html);

        if ($block === null) {
            return null;
        }

        [$fromName, $from] = self::person($block['from'] ?? '');

        return [
            'message_id' => null,
            'subject' => trim($block['subject'] ?? ''),
            'from' => $from,
            'from_name' => $fromName,
            'to' => self::addresses($block['to'] ?? ''),
            'cc' => self::addresses($block['cc'] ?? ''),
            'at' => self::time($block['at'] ?? '')?->toIso8601String(),
            'body' => $block['body'],
            'attachments' => ($message['attachments'] ?? null) instanceof Closure ? ($message['attachments'])() : (array) ($message['attachments'] ?? []),
            'exact' => false,
        ];
    }

    /**
     * The forwarder's own words: the text above the quoted email (all of it when none is quoted).
     */
    public static function note(string $html): string
    {
        $offset = self::headerOffset($html);

        return self::text($offset !== null ? substr($html, 0, $offset) : $html);
    }

    /**
     * The quoted header — its fields, and the original's text after it.
     *
     * @return array<string, string>|null
     */
    private static function headerBlock(string $html): ?array
    {
        $offset = self::headerOffset($html);

        if ($offset === null) {
            return null;
        }

        $fields = [];
        $lines = explode("\n", self::text(substr($html, $offset)));

        foreach (array_slice($lines, 0, 12) as $line) {
            foreach (self::LABELS as $field => $labels) {
                if (! isset($fields[$field]) && preg_match('/^\s*(?:'.self::alternatives($labels).')\s*:\s*(.*)$/u', $line, $m)) {
                    $fields[$field] = trim($m[1]);

                    continue 2;
                }
            }
        }

        if (! isset($fields['from'], $fields['subject'])) {
            return null;
        }

        // The text after the header's last line ("Subject: …" — or a "To"/"Cc" after it).
        $rest = substr($html, $offset);
        $end = 0;
        foreach (['subject', 'to', 'cc'] as $field) {
            if (preg_match('/(?:'.self::alternatives(self::LABELS[$field]).')(?:\s|<[^>]*>)*:/u', $rest, $m, PREG_OFFSET_CAPTURE)
                && preg_match('#<br\s*/?>|</p>|</div>|\n#i', $rest, $close, PREG_OFFSET_CAPTURE, $m[0][1])) {
                $end = max($end, $close[0][1] + strlen($close[0][0]));
            }
        }

        return [...$fields, 'body' => trim(substr($rest, $end))];
    }

    /**
     * Where the quoted header begins: its "From:" (من:) line with a "Subject:" (الموضوع:) within a few lines.
     */
    private static function headerOffset(string $html): ?int
    {
        $from = '/(?:'.self::alternatives(self::LABELS['from']).')(?:\s|<[^>]*>)*:/u';
        $position = 0;

        while (preg_match($from, $html, $m, PREG_OFFSET_CAPTURE, $position)) {
            $start = $m[0][1];
            $window = self::text(substr($html, $start, 3000));

            if (preg_match('/^\s*(?:'.self::alternatives(self::LABELS['subject']).')\s*:/mu', implode("\n", array_slice(explode("\n", $window), 0, 10)))) {
                // From the start of the line it is on (a "<b>From:</b>" begins with its tag).
                $lineStart = max(strrpos(substr($html, 0, $start), '<br') ?: 0, strrpos(substr($html, 0, $start), '<p') ?: 0, strrpos(substr($html, 0, $start), '<div') ?: 0);

                return $lineStart;
            }

            $position = $start + strlen($m[0][0]);
        }

        return null;
    }

    /** HTML as plain lines (EmailText), no empty ones. */
    private static function text(string $html): string
    {
        return trim((string) preg_replace("/\n\s*\n+/u", "\n", EmailText::plain($html)));
    }

    /** "Name <a@b>", "Name [mailto:a@b]", "a@b" — or a name alone (Outlook for a colleague). */
    private static function person(string $value): array
    {
        if (preg_match('/^(.*?)[<\[](?:mailto:)?([^>\]\s]+@[^>\]\s]+)[>\]]/u', $value, $m)) {
            return [trim($m[1], " \"'"), mb_strtolower(trim($m[2]))];
        }

        if (preg_match('/^\S+@\S+$/', trim($value))) {
            return ['', mb_strtolower(trim($value))];
        }

        return [trim($value), ''];
    }

    /** @return list<string> */
    private static function addresses(string $value): array
    {
        preg_match_all('/[^\s<>\[\];,"\']+@[^\s<>\[\];,"\']+/u', $value, $m);

        return array_values(array_unique(array_map('mb_strtolower', $m[0])));
    }

    /**
     * The time the header says it was sent, in the office's time: "Thursday, October 23, 2026 7:27 AM",
     * "Thu, Oct 23, 2026 at 7:27 AM", "الخميس، 23 أكتوبر، 2026 07:27 ص".
     */
    public static function time(string $value): ?CarbonInterface
    {
        $value = strtr(trim($value), ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9', '،' => ',']);

        foreach (self::ARABIC_MONTHS as $arabic => $english) {
            $value = str_replace($arabic, ' '.$english.' ', $value);
        }

        $value = preg_replace(['/\s+ص\b/u', '/\s+م\b/u', '/(?:الأحد|الاثنين|الإثنين|الثلاثاء|الأربعاء|الخميس|الجمعة|السبت)\s*,?/u', '/\s+at\s+/i'], [' AM', ' PM', '', ' '], $value) ?? $value;
        // Commas out: "22 October, 2026" (Arabic Outlook's) is not read with one.
        $value = trim(preg_replace('/[\s,]+/u', ' ', $value) ?? $value);

        if ($value === '') {
            return null;
        }

        try {
            $time = Carbon::parse($value, config('app.timezone'));

            return $time->year >= 1971 ? $time : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** @param  list<string>  $labels */
    private static function alternatives(array $labels): string
    {
        // The longest first: "نسخة إلى" before "نسخة".
        usort($labels, fn (string $one, string $two): int => mb_strlen($two) <=> mb_strlen($one));

        return implode('|', array_map(fn (string $label): string => preg_quote($label, '/'), $labels));
    }

    private static function headerValue(string $header, string $name): string
    {
        // Folded lines joined.
        $header = preg_replace("/\n[ \t]+/", ' ', $header) ?? $header;

        return preg_match('/^'.preg_quote($name, '/').':\s*(.*)$/im', $header, $m) ? trim($m[1]) : '';
    }

    private static function decoded(string $body, string $encoding): string
    {
        return match (strtolower(trim($encoding))) {
            'base64' => (string) base64_decode(preg_replace('/\s+/', '', $body) ?? ''),
            'quoted-printable' => quoted_printable_decode($body),
            default => $body,
        };
    }
}
