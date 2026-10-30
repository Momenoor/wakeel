<?php

namespace App\Services\MMS\Letters;

use App\Enums\LetterStatus;
use App\Mail\LetterEmail;
use App\Models\EmailTemplate;
use App\Models\MatterContact;
use App\Models\MatterEmail;
use App\Models\MatterLetter;
use App\Models\MatterLetterRecipient;
use App\Services\MMS\BulkMailPlaceholders;
use App\Services\MMS\MatterProgressRecorder;
use App\Services\MMS\SenderMailer;
use App\Services\MMS\SentEmailArchive;
use App\Services\MMS\SentFolder;
use App\Support\Addresses;
use App\Support\EmailGrouping;
use App\Support\Honorific;
use App\Support\RichHtml;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

use function Illuminate\Support\defer;

/**
 * Emails an issued letter through one of the department mailboxes:
 *
 *  - as an attachment: a covering email (an email template, or a short
 *    built-in note) with the letter attached as PDF and/or Word;
 *  - as the body: the letter itself, reference and date on top.
 *
 * To everyone in one email, one to each recipient with their
 * representatives, or one to each person (EmailGrouping) — so
 * {{recipient.name}} greets each by name. In the email's To, each address is
 * named after whose it is: the party on its own, its lawyer on the lawyer's.
 * Each recipient is marked sent or failed, with the reason; the letter is
 * marked Sent when any went out.
 */
class LetterMailer
{
    public const ATTACHMENT = 'attachment';

    public const BODY = 'body';

    /**
     * @param  list<int>|null  $recipientIds  MatterLetterRecipient ids — exactly these (none ticked: none of them); null: every recipient of the letter
     * @param  list<string>  $formats  'pdf' and/or 'docx'
     * @param  list<string>  $cc
     * @param  string|bool  $separate  EmailGrouping — one each, by party, or one for all (true: by party; false: all)
     * @param  ?string  $subject  this send's own subject, instead of the template's
     * @param  ?string  $body  this send's own covering email, instead of the template's
     * @param  list<array{path: string, name: string}>  $attachments  more files to send with the letter, either way it goes
     * @param  list<string>|null  $contactsCc  of the addresses recipients brought in (MatterContact), those to copy in on the email to them — null: all
     * @return array{sent: int, failed: int, skipped: int, errors: list<string>}
     */
    public function send(
        MatterLetter $letter,
        string $senderKey,
        string $mode = self::ATTACHMENT,
        ?EmailTemplate $template = null,
        array $formats = ['pdf'],
        ?array $recipientIds = null,
        array $cc = [],
        string|bool $separate = false,
        ?string $subject = null,
        ?string $body = null,
        array $attachments = [],
        ?array $contactsCc = null,
    ): array {
        $sender = SenderMailer::sender($senderKey);
        $composer = LetterIssuer::composerFor($letter);

        $recipients = $letter->recipients
            ->when($recipientIds !== null, fn (Collection $all) => $all->whereIn('id', $recipientIds))
            ->values();

        $withEmail = $recipients->filter(fn (MatterLetterRecipient $r) => $this->emails($r) !== []);
        $result = ['sent' => 0, 'failed' => 0, 'skipped' => $recipients->count() - $withEmail->count(), 'errors' => []];

        if ($withEmail->isEmpty() && $cc === []) {
            return $result;
        }

        $files = [
            ...($mode === self::ATTACHMENT ? $this->files($letter, $composer, $formats) : []),
            ...self::attachedFiles($attachments),
        ];
        $grouping = EmailGrouping::from($separate);
        $cc = Addresses::emails($cc);
        // Every inbox once: a party and its representative (or two parties)
        // sharing an address get one email, not one each.
        $reached = [];
        // Each recipient sent if any of their emails went (separately, a
        // party's and each representative's are their own).
        $sentIds = [];
        $failedIds = [];
        $withoutRecipients = ['sent' => 0, 'failed' => 0];
        // Whom it reached, for the matter's progress.
        $names = [];

        foreach (self::emailGroups($withEmail, $grouping) as $unit) {
            $group = $unit['recipients'];
            $to = Addresses::without($unit['emails'], $reached);
            // Each address under the name of whose it is.
            $named = array_values(array_filter($unit['to'], fn (array $address): bool => in_array($address['email'], $to, true)));

            if ($to === [] && $reached !== []) {
                // All of theirs had it already, in an earlier email.
                $sentIds = [...$sentIds, ...$group->pluck('id')->all()];

                continue;
            }

            $reached = [...$reached, ...$to];
            // …and whom these recipients brought into the matter.
            $theirs = MatterContact::copiedFor($letter->matter_id, $to, $group->pluck('recipient_id')->filter()->all(), $contactsCc);
            $groupCc = Addresses::without(Addresses::emails([...$cc, ...$theirs]), $to);
            $email = $this->email($composer, $mode, $template, $unit['greet'], $files, $subject, $body, $unit['name'], $sender);

            try {
                try {
                    $sent = SenderMailer::using($sender, fn () => Mail::to($to ? $named : $groupCc)->cc($to ? $groupCc : [])->send($email));
                } catch (\Throwable $refusal) {
                    if (! self::refusedAddress($refusal)) {
                        throw $refusal;
                    }

                    // An address that does not exist stops the whole email at the mail server ("550 No Such User Here"):
                    // each is tried on its own, the others get it, and the ones refused are named.
                    [$sent, $to, $groupCc, $refused] = $this->withoutRefused($sender, $email, $to, $groupCc, $refusal, array_column($named, 'name', 'email'));
                    $named = array_values(array_filter($named, fn (array $address): bool => in_array($address['email'], $to, true)));
                    $result['errors'][] = __('The mail server refused these addresses (they do not exist or cannot receive): :addresses', ['addresses' => implode(', ', $refused)]);
                }

                // Remembered, for its replies.
                $record = MatterEmail::recordSent($letter->matter_id, $letter, $senderKey, $sent?->getMessageId(), $email->emailSubject, [...$to, ...$groupCc], auth()->id());

                $sentIds = [...$sentIds, ...$group->pluck('id')->all()];
                $withoutRecipients['sent'] += $group->isEmpty() ? 1 : 0;
                $names = [...$names, ...($unit['name'] !== null ? [$unit['name']] : $group->pluck('name')->all())];

                $this->copyToSentFolder($senderKey, $sent?->toString());

                // Kept with the matter, as it went — once the page has
                // answered: a PDF takes a moment, and an email each adds up.
                $keepTo = $to ? $named : $groupCc;
                $keepCc = $to ? $groupCc : [];
                $userId = auth()->id();
                defer(fn () => $this->keepOnMatter($letter, $email, $sender, $group, $keepTo, $keepCc, $userId, $record));
            } catch (\Throwable $e) {
                foreach ($group as $recipient) {
                    $failedIds[$recipient->id] = Str::limit($e->getMessage(), 1000);
                }
                $withoutRecipients['failed'] += $group->isEmpty() ? 1 : 0;
                $result['errors'][] = $e->getMessage();
            }
        }

        $sentIds = array_values(array_unique($sentIds));
        foreach ($withEmail as $recipient) {
            if (in_array($recipient->id, $sentIds, true)) {
                $recipient->update(['delivery_status' => LetterStatus::SENT, 'delivered_at' => now(), 'failure_reason' => null]);
            } elseif (isset($failedIds[$recipient->id])) {
                $recipient->update(['delivery_status' => LetterStatus::FAILED, 'failure_reason' => $failedIds[$recipient->id]]);
            }
        }

        $result['sent'] = count($sentIds) + $withoutRecipients['sent'];
        $result['failed'] = count(array_diff(array_keys($failedIds), $sentIds)) + $withoutRecipients['failed'];

        if ($result['sent'] > 0) {
            $letter->update([
                'status' => LetterStatus::SENT,
                'sent_at' => now(),
                'sender_key' => $senderKey,
            ]);

            MatterProgressRecorder::letterSent($letter, $names ?: $cc, auth()->id());
        }

        return $result;
    }

    /**
     * The email as sent — sender, recipients, date, subject, its text and
     * the names of what was attached — as a PDF among the matter's
     * attachments. Sending never fails for it: a PDF that can't be made is
     * logged, the email is still sent.
     *
     * @param  array<string, mixed>  $sender
     * @param  Collection<int, MatterLetterRecipient>  $group
     * @param  list<string|array{email: string, name: string}>  $to
     * @param  list<string>  $cc
     */
    private function keepOnMatter(MatterLetter $letter, LetterEmail $email, array $sender, Collection $group, array $to, array $cc, ?int $userId, ?MatterEmail $record = null): void
    {
        if ($letter->matter) {
            app(SentEmailArchive::class)->keepEmail($letter->matter, $email, $sender, $group->pluck('name')->filter()->implode('، '), $to, $cc, (string) $letter->reference, $userId, $record);
        }
    }

    /**
     * @return list<string>
     */
    private function emails(MatterLetterRecipient $recipient): array
    {
        // Their own, and their representatives' — who go with them.
        return $recipient->allEmails();
    }

    /**
     * The subject and covering email to start from in the send screen: the
     * template's (or the built-in note), with the letter's details already
     * filled in — {{recipient.name}} stays, for each recipient's own. Edited
     * there, it's used for that one send only; the template stays as it is.
     *
     * @return array{subject: string, body: ?string}
     */
    public function draft(MatterLetter $letter, string $mode, ?EmailTemplate $template): array
    {
        $composer = LetterIssuer::composerFor($letter);
        $values = array_map('strip_tags', $composer->values());

        if ($mode === self::BODY) {
            return ['subject' => self::bodySubject($values), 'body' => null];
        }

        return [
            'subject' => BulkMailPlaceholders::apply($template?->subject ?? '{{reference}} — {{subject}}', $values),
            'body' => BulkMailPlaceholders::apply(LetterComposer::normalizeMergeTags($template?->body ?? self::defaultCoverNote($composer->isArabic())), $values, escape: true),
        ];
    }

    /**
     * The email exactly as it will go to one recipient (no attachments),
     * its images inline — for the preview before sending.
     *
     * @return array{subject: string, html: string, rtl: bool}
     */
    public function preview(MatterLetter $letter, string $mode, ?EmailTemplate $template, ?MatterLetterRecipient $recipient, ?string $subject = null, ?string $body = null, ?string $name = null, ?string $senderKey = null): array
    {
        $sender = filled($senderKey) ? (SenderMailer::all()[$senderKey] ?? null) : null;
        $email = $this->email(LetterIssuer::composerFor($letter), $mode, $template, $recipient, [], $subject, $body, $name, $sender);

        $html = $email->letterHtml;
        foreach ($email->images as $token => $path) {
            $html = str_replace($token, 'data:'.(mime_content_type($path) ?: 'image/png').';base64,'.base64_encode((string) file_get_contents($path)), $html);
        }

        return ['subject' => $email->emailSubject, 'html' => $html, 'rtl' => $email->rtl];
    }

    /**
     * The emails to send: one for all (no one greeted by name); one a
     * recipient with their representatives; or one each — the recipient,
     * then each representative on their own. Each one's addresses ('to')
     * named after whose they are, the plain addresses beside them.
     *
     * @param  Collection<int, MatterLetterRecipient>  $recipients
     * @return list<array{recipients: Collection<int, MatterLetterRecipient>, to: list<array{email: string, name: string}>, emails: list<string>, greet: ?MatterLetterRecipient, name: ?string}>
     */
    public static function emailGroups(Collection $recipients, string $grouping): array
    {
        $unit = fn (Collection $group, array $to, ?MatterLetterRecipient $greet, ?string $name): array => [
            'recipients' => $group,
            'to' => $to,
            'emails' => array_column($to, 'email'),
            'greet' => $greet,
            'name' => $name,
        ];

        if ($grouping === EmailGrouping::ALL || $recipients->isEmpty()) {
            return [$unit($recipients->values(), Addresses::named($recipients->flatMap(fn (MatterLetterRecipient $r) => $r->addressRows())), null, null)];
        }

        $units = [];
        foreach ($recipients as $recipient) {
            if ($grouping === EmailGrouping::BY_PARTY) {
                $units[] = $unit(collect([$recipient]), $recipient->addressed(), $recipient, null);

                continue;
            }

            // Each person on their own: the party first, then each representative.
            foreach ($recipient->addressRows() as $i => $row) {
                $to = Addresses::named([$row]);
                if ($to !== []) {
                    $units[] = $unit(collect([$recipient]), $to, $recipient, $i === 0 ? null : ($row['name'] ?: null));
                }
            }
        }

        return $units;
    }

    /**
     * Files added to this one send, as attachments.
     *
     * @param  list<array{path: string, name: string}>  $attachments
     * @return list<array{name: string, data: string, mime: string}>
     */
    public static function attachedFiles(array $attachments): array
    {
        return array_values(array_filter(array_map(
            fn (array $file): ?array => is_file($file['path'])
                ? ['name' => $file['name'], 'data' => (string) file_get_contents($file['path']), 'mime' => mime_content_type($file['path']) ?: 'application/octet-stream']
                : null,
            $attachments,
        )));
    }

    /**
     * @param  array<string, string>  $values
     */
    private static function bodySubject(array $values): string
    {
        return trim($values['reference'].' — '.$values['subject'], ' —');
    }

    /**
     * @param  list<array{name: string, data: string, mime: string}>  $files
     */
    private function email(LetterComposer $composer, string $mode, ?EmailTemplate $template, ?MatterLetterRecipient $recipient, array $files, ?string $subjectOverride = null, ?string $bodyOverride = null, ?string $name = null, ?array $sender = null): LetterEmail
    {
        $values = [
            ...$composer->values(),
            // A representative's own email: greeted by their name.
            ...Honorific::values((string) ($name ?? $recipient?->name ?? ''), $composer->isArabic()),
            'recipient.role' => $name !== null ? '' : (string) ($recipient?->role ?? ''),
        ];

        if ($mode === self::BODY) {
            $arabic = $composer->isArabic();
            $header = '<p style="margin: 0;">'.($arabic ? 'المرجع: ' : 'Ref: ').'<span dir="ltr">'.e($values['reference']).'</span></p>'
                .'<p style="margin: 0 0 16px 0;">'.($arabic ? 'التاريخ: ' : 'Date: ').e($values['date']).'</p>';

            // The letter is the email: its own signature and stamp go, the mailbox's signature follows it.
            $own = clone $composer;
            $own->withoutSignature = true;

            [$html, $images] = $this->embeddable(SenderMailer::withSignature($header.SignatureLayouts::forEmail($own->bodyHtml()), $sender));

            return new LetterEmail(
                BulkMailPlaceholders::apply(filled($subjectOverride) ? $subjectOverride : self::bodySubject($values), array_map('strip_tags', $values)),
                $html,
                $arabic,
                $images,
                // Only what was added: the letter is the email itself.
                $files,
            );
        }

        $subject = filled($subjectOverride) ? $subjectOverride : ($template?->subject ?? '{{reference}} — {{subject}}');
        $body = filled(strip_tags((string) $bodyOverride)) ? $bodyOverride : ($template?->body ?? self::defaultCoverNote($composer->isArabic()));
        $rtl = ($template?->locale ?? ($composer->isArabic() ? 'ar' : 'en')) !== 'en';
        $html = BulkMailPlaceholders::apply(LetterComposer::normalizeMergeTags($body), array_map('strip_tags', $values), escape: true);
        // Outlook knows no start or end: the editor's alignment as left/right.
        [$html, $images] = $this->embeddable(SenderMailer::withSignature(RichHtml::forOutput($html, $rtl), $sender));

        return new LetterEmail(
            BulkMailPlaceholders::apply($subject, array_map('strip_tags', $values)),
            $html,
            $rtl,
            $images,
            $files,
        );
    }

    /**
     * The covering email when no email template has been set up.
     */
    public static function defaultCoverNote(bool $arabic): string
    {
        return $arabic
            ? '<p>تحية طيبة وبعد،</p><p>نرفق لكم طيه الخطاب رقم {{reference}} بشأن: {{subject}}.</p><p>وتفضلوا بقبول وافر الاحترام والتقدير،</p>'
            : '<p>Dear Sir/Madam,</p><p>Please find attached our letter {{reference}} regarding: {{subject}}.</p><p>Kind regards,</p>';
    }

    /**
     * Local image paths (signature, stamp) swapped for tokens the email
     * view replaces with embedded images.
     *
     * @return array{0: string, 1: array<string, string>}
     */
    private function embeddable(string $html): array
    {
        $images = [];

        $html = preg_replace_callback('/<img([^>]*)src="([^"]+)"/u', function (array $m) use (&$images) {
            $path = html_entity_decode($m[2]);
            if (! is_file($path)) {
                return $m[0];
            }

            $token = 'cid-letter-image-'.count($images);
            $images[$token] = $path;

            return '<img'.$m[1].'src="'.$token.'"';
        }, $html) ?? $html;

        return [$html, $images];
    }

    /**
     * @param  list<string>  $formats
     * @return list<array{name: string, data: string, mime: string}>
     */
    private function files(MatterLetter $letter, LetterComposer $composer, array $formats): array
    {
        $name = LetterIssuer::fileName($letter);
        $files = [];

        if (in_array('pdf', $formats, true)) {
            $files[] = ['name' => $name.'.pdf', 'data' => (new LetterPdf($composer))->render(), 'mime' => 'application/pdf'];
        }

        if (in_array('docx', $formats, true)) {
            $path = (new LetterDocx($composer))->save(storage_path('app/temp/letter-'.$letter->getKey().'-'.uniqid().'.docx'));
            $files[] = [
                'name' => $name.'.docx',
                'data' => (string) file_get_contents($path),
                'mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            ];
            @unlink($path);
        }

        return $files;
    }

    /**
     * Whether a send failed because the mail server refused an address: "550 No
     * Such User Here", "user unknown", "mailbox unavailable" …
     */
    public static function refusedAddress(\Throwable $e): bool
    {
        for ($each = $e; $each !== null; $each = $each->getPrevious()) {
            $message = $each->getMessage();

            if (preg_match('/\b55[0-4]\b/', $message) && preg_match('/no such user|user unknown|unknown user|does not exist|no mailbox|invalid (recipient|address|mailbox)|recipient (address )?(rejected|refused|not found)|mailbox (unavailable|not found|unknown)|user not found|address rejected/i', $message)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The email sent to each address on its own, after the whole of it was refused for one: those that took it, and
     * those that were refused. Nothing went before — the server turns the email away before it is sent.
     *
     * @param  list<string>  $to
     * @param  list<string>  $cc
     * @return array{0: mixed, 1: list<string>, 2: list<string>, 3: list<string>} the sent message, who it reached in To and in Cc, who was refused
     *
     * @throws \Throwable when no address took it, or for another reason
     */
    private function withoutRefused(array $sender, LetterEmail $email, array $to, array $cc, \Throwable $refusal, array $names = []): array
    {
        $sent = null;
        $reached = [];
        $refused = [];

        foreach (array_values(array_unique([...$to, ...$cc])) as $address) {
            // The email as built, with no one on it from the attempt before.
            $fresh = clone $email;
            $fresh->to = [];
            $fresh->cc = [];
            $fresh->bcc = [];

            try {
                $one = SenderMailer::using($sender, fn () => Mail::to([['email' => $address, 'name' => $names[$address] ?? '']])->send($fresh));
                $sent ??= $one;
                $reached[] = $address;
            } catch (\Throwable $e) {
                if (! self::refusedAddress($e)) {
                    throw $e;
                }

                $refused[] = $address;
            }
        }

        if ($sent === null) {
            throw new \RuntimeException(__('The mail server refused every address (they do not exist or cannot receive): :addresses', ['addresses' => implode(', ', $refused)]), 0, $refusal);
        }

        return [$sent, array_values(array_intersect($to, $reached)), array_values(array_intersect($cc, $reached)), $refused];
    }

    /**
     * Sent already; a Sent-folder copy that fails is only logged.
     */
    private function copyToSentFolder(string $senderKey, ?string $raw): void
    {
        if ($raw === null) {
            return;
        }

        try {
            app(SentFolder::class)->saveFor($senderKey, $raw);
        } catch (\Throwable $e) {
            Log::warning("Letter email sent but not copied to the {$senderKey} Sent folder: ".$e->getMessage());
        }
    }
}
