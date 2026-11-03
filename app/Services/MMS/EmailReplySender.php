<?php

namespace App\Services\MMS;

use App\Filament\Support\EmailSendFields;
use App\Mail\LetterEmail;
use App\Models\MatterContact;
use App\Models\MatterEmail;
use App\Models\Party;
use App\Services\MMS\Letters\LetterMailer;
use App\Services\MMS\Letters\SharedAttachments;
use App\Support\Addresses;
use App\Support\Documents;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Throwable;

/**
 * A reply to an email that came in on a matter, written on its Emails tab: to whoever wrote it — and, when they
 * wrote from another address of a party's, to the party's own too — copied to the others it reached, under the
 * same thread (In-Reply-To), with the files added and, if wanted, the email replied to as its PDF; sent from one
 * of the office's mailboxes, kept with the matter beside the email it answers.
 */
final class EmailReplySender
{
    public function __construct(private readonly SharedAttachments $shared, private readonly SentEmailArchive $archive) {}

    /**
     * Whom the reply goes to: the sender, and — when that address is a party's other email on the matter — the
     * party's own.
     *
     * @return list<string>
     */
    public static function to(MatterEmail $email): array
    {
        $from = self::from($email);
        $party = self::partyWritingFrom($email);

        return array_values(array_unique(Addresses::emails([$from, ...($party && ! in_array($from, array_map('mb_strtolower', Party::values($party->email)), true) ? Party::values($party->email) : [])])));
    }

    /**
     * Whom to copy in: the others the email was sent to and copied to — the office's own mailboxes left out.
     *
     * @return list<string>
     */
    public static function cc(MatterEmail $email): array
    {
        $from = self::from($email);

        return array_values(array_filter(
            array_unique(Addresses::emails([...(array) $email->to, ...(array) $email->cc])),
            fn (string $address): bool => mb_strtolower($address) !== $from && ! MatterContact::isOurs($address),
        ));
    }

    /** "RE: " before the subject, unless it is a reply already. */
    public static function subject(MatterEmail $email): string
    {
        $subject = trim((string) $email->subject);

        return preg_match('/^(re|رد)\s*:/iu', $subject) ? $subject : 'RE: '.$subject;
    }

    /**
     * The sender's party on the matter, by any address of theirs — for the other emails they brought in.
     *
     * @return list<int>
     */
    public static function partyIds(MatterEmail $email): array
    {
        $party = self::partyWritingFrom($email) ?? (($id = MatterContact::partyOf((int) $email->matter_id, self::from($email))) ? Party::find($id) : null);

        return $party ? [(int) $party->getKey()] : [];
    }

    /**
     * The reply sent, kept with the matter beside the email it answers, and what went wrong if anything did.
     *
     * @param  array<string, mixed>  $data  the screen's: sender, to, cc, contacts_cc, subject, body, attachments, onedrive_files, attach_original
     * @return array{sent: bool, errors: list<string>}
     */
    public function send(MatterEmail $email, array $data, ?int $userId): array
    {
        $matter = $email->matter;
        $senderKey = (string) $data['sender'];
        $sender = SenderMailer::sender($senderKey);
        $to = array_values(array_unique(Addresses::emails((array) ($data['to'] ?? []))));
        $cc = Addresses::without(Addresses::emails([
            ...(array) ($data['cc'] ?? []),
            ...MatterContact::copiedFor($email->matter_id, $to, self::partyIds($email), (array) ($data['contacts_cc'] ?? [])),
        ]), $to);

        if ($to === []) {
            throw new RuntimeException(__('Whom the reply goes to is missing.'));
        }

        $subject = trim((string) ($data['subject'] ?? '')) ?: self::subject($email);
        $body = (string) ($data['body'] ?? '');
        $arabic = preg_match('/\p{Arabic}/u', $subject.strip_tags($body)) === 1;
        $files = [
            ...(! empty($data['attach_original']) ? self::originalPdf($email) : []),
            ...LetterMailer::attachedFiles(EmailSendFields::uploaded($data, $matter)),
        ];
        $errors = [];

        try {
            // Over what an email takes: the files go by a link, in a folder named after the reply.
            ['files' => $files, 'shared' => $shared] = $this->shared->offload($matter, $files, 0, $subject, $to);

            if ($shared !== null && ! $shared['anyone']) {
                $errors[] = __('Microsoft 365 allows no links for anyone here: the link to the files opens for the office\'s own accounts only.');
            }

            $mailable = new LetterEmail(
                $subject,
                SenderMailer::withSignature(SharedAttachments::withNote($body, $shared, $arabic), $sender),
                $arabic,
                [],
                $files,
                SharedAttachments::listed($shared),
                inReplyTo: $email->message_id,
            );

            $sent = SenderMailer::using($sender, fn () => Mail::to($to)->cc($cc)->send($mailable));
        } catch (Throwable $e) {
            EmailSendFields::forget($data);

            return ['sent' => false, 'errors' => [...$errors, SenderMailer::reason($e, $sender)]];
        }

        // Remembered beside the email it answers, for its own replies to be found.
        $record = MatterEmail::recordSent($email->matter_id, $email, $senderKey, $sent?->getMessageId(), $subject, [...$to, ...$cc], $userId);
        $record?->update(['parent_id' => $email->getKey(), 'matched_by' => MatterEmail::BY_REPLY]);

        if ($notCopied = SentFolder::copyQuietly($senderKey, $sent?->toString())) {
            $errors[] = $notCopied;
        }

        // Kept with the matter as it went — once the page has answered: a PDF takes a moment.
        if ($matter) {
            $names = implode('، ', array_filter([$email->from, ...$to]));
            defer(fn () => $this->archive->keepEmail($matter, $mailable, $sender, $names, $to, $cc, $subject, $userId, $record));
        }

        EmailSendFields::forget($data);

        return ['sent' => true, 'errors' => $errors];
    }

    /** The email replied to, as its PDF kept in the system — nothing when none was kept. */
    public static function hasOriginalPdf(MatterEmail $email): bool
    {
        return self::originalPdf($email) !== [];
    }

    /**
     * @return list<array{name: string, data: string, mime: string}>
     */
    private static function originalPdf(MatterEmail $email): array
    {
        $pdf = ((array) $email->files)[0] ?? null;
        $path = is_array($pdf) ? (string) ($pdf['path'] ?? '') : '';
        $storage = $path !== '' ? Documents::storage($path) : null;

        if ($storage === null || ! str_ends_with(mb_strtolower($path), '.pdf') || ! $storage->exists($path)) {
            return [];
        }

        return [['name' => (string) ($pdf['name'] ?: basename($path)), 'data' => (string) $storage->get($path), 'mime' => 'application/pdf']];
    }

    /** The party whose other email on the matter the email came from, if it is one's. */
    private static function partyWritingFrom(MatterEmail $email): ?Party
    {
        $partyId = MatterContact::query()
            ->where('matter_id', $email->matter_id)
            ->where('email', self::from($email))
            ->whereNotNull('party_id')
            ->value('party_id');

        return $partyId ? Party::find($partyId) : null;
    }

    private static function from(MatterEmail $email): string
    {
        return mb_strtolower(MatterEmail::addressOf((string) $email->from));
    }
}
