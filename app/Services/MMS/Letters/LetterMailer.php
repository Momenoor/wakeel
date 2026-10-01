<?php

namespace App\Services\MMS\Letters;

use App\Enums\LetterStatus;
use App\Mail\LetterEmail;
use App\Models\EmailTemplate;
use App\Models\MatterLetter;
use App\Models\MatterLetterRecipient;
use App\Services\MMS\BulkMailPlaceholders;
use App\Services\MMS\SenderMailer;
use App\Services\MMS\SentFolder;
use App\Support\TextDirection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Emails an issued letter through one of the department mailboxes:
 *
 *  - as an attachment: a covering email (an email template, or a short
 *    built-in note) with the letter attached as PDF and/or Word;
 *  - as the body: the letter itself, reference and date on top.
 *
 * To everyone in one email, or a separate email to each recipient (so
 * {{recipient.name}} greets each by name). Each recipient is marked sent
 * or failed, with the reason; the letter is marked Sent when any went out.
 */
class LetterMailer
{
    public const ATTACHMENT = 'attachment';

    public const BODY = 'body';

    /**
     * @param  list<int>  $recipientIds  MatterLetterRecipient ids
     * @param  list<string>  $formats  'pdf' and/or 'docx'
     * @param  list<string>  $cc
     * @param  ?string  $subject  this send's own subject, instead of the template's
     * @param  ?string  $body  this send's own covering email, instead of the template's
     * @param  list<array{path: string, name: string}>  $attachments  more files to send with the letter, either way it goes
     * @return array{sent: int, failed: int, skipped: int, errors: list<string>}
     */
    public function send(
        MatterLetter $letter,
        string $senderKey,
        string $mode = self::ATTACHMENT,
        ?EmailTemplate $template = null,
        array $formats = ['pdf'],
        array $recipientIds = [],
        array $cc = [],
        bool $separate = false,
        ?string $subject = null,
        ?string $body = null,
        array $attachments = [],
    ): array {
        $sender = SenderMailer::sender($senderKey);
        $composer = LetterIssuer::composerFor($letter);

        $recipients = $letter->recipients
            ->when($recipientIds !== [], fn (Collection $all) => $all->whereIn('id', $recipientIds))
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
        $groups = $separate ? $withEmail->map(fn ($r) => collect([$r])) : collect([$withEmail]);

        foreach ($groups as $group) {
            $to = $group->flatMap(fn (MatterLetterRecipient $r) => $this->emails($r))->unique()->values()->all();
            $email = $this->email($composer, $mode, $template, $separate ? $group->first() : null, $files, $subject, $body);

            try {
                $sent = SenderMailer::using($sender, fn () => Mail::to($to ?: $cc)->cc($to ? $cc : [])->send($email));

                $group->each(fn (MatterLetterRecipient $r) => $r->update([
                    'delivery_status' => LetterStatus::SENT,
                    'delivered_at' => now(),
                    'failure_reason' => null,
                ]));
                $result['sent'] += max(1, $group->count());

                $this->copyToSentFolder($senderKey, $sent?->toString());
            } catch (\Throwable $e) {
                $group->each(fn (MatterLetterRecipient $r) => $r->update([
                    'delivery_status' => LetterStatus::FAILED,
                    'failure_reason' => Str::limit($e->getMessage(), 1000),
                ]));
                $result['failed'] += max(1, $group->count());
                $result['errors'][] = $e->getMessage();
            }
        }

        if ($result['sent'] > 0) {
            $letter->update([
                'status' => LetterStatus::SENT,
                'sent_at' => now(),
                'sender_key' => $senderKey,
            ]);
        }

        return $result;
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
    public function preview(MatterLetter $letter, string $mode, ?EmailTemplate $template, ?MatterLetterRecipient $recipient, ?string $subject = null, ?string $body = null): array
    {
        $email = $this->email(LetterIssuer::composerFor($letter), $mode, $template, $recipient, [], $subject, $body);

        $html = $email->letterHtml;
        foreach ($email->images as $token => $path) {
            $html = str_replace($token, 'data:'.(mime_content_type($path) ?: 'image/png').';base64,'.base64_encode((string) file_get_contents($path)), $html);
        }

        return ['subject' => $email->emailSubject, 'html' => $html, 'rtl' => $email->rtl];
    }

    /**
     * Files added to this one send, as attachments.
     *
     * @param  list<array{path: string, name: string}>  $attachments
     * @return list<array{name: string, data: string, mime: string}>
     */
    private static function attachedFiles(array $attachments): array
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
    private function email(LetterComposer $composer, string $mode, ?EmailTemplate $template, ?MatterLetterRecipient $recipient, array $files, ?string $subjectOverride = null, ?string $bodyOverride = null): LetterEmail
    {
        $values = [
            ...$composer->values(),
            'recipient.name' => (string) ($recipient?->name ?? ''),
            'recipient.role' => (string) ($recipient?->role ?? ''),
        ];

        if ($mode === self::BODY) {
            $arabic = $composer->isArabic();
            $header = '<p style="margin: 0;">'.($arabic ? 'المرجع: ' : 'Ref: ').'<span dir="ltr">'.e($values['reference']).'</span></p>'
                .'<p style="margin: 0 0 16px 0;">'.($arabic ? 'التاريخ: ' : 'Date: ').e($values['date']).'</p>';

            // A signature block: its lines, then its picture — no layering in mail.
            [$html, $images] = $this->embeddable($header.SignatureLayouts::forEmail($composer->bodyHtml()));

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
        [$html, $images] = $this->embeddable(TextDirection::physicalAlignment($html, $rtl));

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
