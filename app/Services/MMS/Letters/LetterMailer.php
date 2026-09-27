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

        $files = $mode === self::ATTACHMENT ? $this->files($letter, $composer, $formats) : [];
        $groups = $separate ? $withEmail->map(fn ($r) => collect([$r])) : collect([$withEmail]);

        foreach ($groups as $group) {
            $to = $group->flatMap(fn (MatterLetterRecipient $r) => $this->emails($r))->unique()->values()->all();
            $email = $this->email($composer, $mode, $template, $separate ? $group->first() : null, $files);

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
        return array_values(array_filter($recipient->emails ?: [$recipient->email], fn ($email) => filter_var($email, FILTER_VALIDATE_EMAIL)));
    }

    /**
     * @param  list<array{name: string, data: string, mime: string}>  $files
     */
    private function email(LetterComposer $composer, string $mode, ?EmailTemplate $template, ?MatterLetterRecipient $recipient, array $files): LetterEmail
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

            [$html, $images] = $this->embeddable($header.$composer->bodyHtml());

            return new LetterEmail(
                trim($values['reference'].' — '.$values['subject'], ' —'),
                $html,
                $arabic,
                $images,
            );
        }

        $subject = $template?->subject ?? '{{reference}} — {{subject}}';
        $body = $template?->body ?? self::defaultCoverNote($composer->isArabic());
        $html = BulkMailPlaceholders::apply(LetterComposer::normalizeMergeTags($body), array_map('strip_tags', $values), escape: true);
        [$html, $images] = $this->embeddable($html);

        return new LetterEmail(
            BulkMailPlaceholders::apply($subject, array_map('strip_tags', $values)),
            $html,
            ($template?->locale ?? ($composer->isArabic() ? 'ar' : 'en')) !== 'en',
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
