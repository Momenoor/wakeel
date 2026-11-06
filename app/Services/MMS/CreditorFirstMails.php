<?php

namespace App\Services\MMS;

use App\Models\BulkMailCampaign;
use App\Models\BulkMailRecipient;
use App\Models\Matter;
use App\Models\MatterContactDetail;
use App\Models\MatterCreditorAddress;
use App\Models\MatterEmail;
use App\Models\Setting;
use App\Support\Addresses;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A matter's first mails to its creditors-to-be — the notices sent before anyone is on its list: the emails of a bulk
 * mail campaign ticked "First mail to creditors", or a sent email marked so on the Emails tab. Saved all in one folder
 * (MatterOneDriveFolders::firstToCreditorsTemplate()), each named as set (nameTemplate(): whom it went to, by default);
 * the day one went to someone is their "First email sent" on the Contacts tab. Those who answer and are made creditors
 * have their emails after it in their own folders.
 */
final class CreditorFirstMails
{
    /** Setting: a first mail's name; the type's own column is NAME_COLUMN. */
    public const NAME_SETTING = 'onedrive_first_mail_name';

    public const NAME_COLUMN = 'first_mail_name';

    /** Named by whom it went to, as nothing else is set. */
    public const DEFAULT_NAME = '{{name}}';

    /** Whom it went to, in its name: no longer than this. */
    public const NAME_LIMIT = 60;

    public function __construct(private readonly MatterOneDriveFolders $folders) {}

    /** A first mail's name pattern on this matter: its type's own, else the setting, else whom it went to. */
    public static function nameTemplate(?Matter $matter): string
    {
        $own = $matter ? trim((string) $matter->loadMissing('type')->type?->getAttribute(self::NAME_COLUMN)) : '';

        return $own !== '' ? $own : (trim((string) Setting::get(self::NAME_SETTING, '')) ?: self::DEFAULT_NAME);
    }

    /**
     * A first mail's name, before ".pdf": its pattern with {{name}} (whom it went to), {{email}} (their address),
     * {{date}}, {{time}}, {{subject}} and the matter's placeholders — a part with no value left out with its " — ".
     */
    public static function name(?Matter $matter, string $name, string $about, ?CarbonInterface $at = null, string $address = ''): string
    {
        $template = self::nameTemplate($matter);
        $at = Carbon::instance($at ?? now())->setTimezone(config('app.timezone'));
        $values = [
            ...(str_contains($template, '{{matter.') ? BulkMailPlaceholders::forMatter($matter) : []),
            'name' => Str::limit(trim((string) preg_replace('/\s+/u', ' ', $name)), self::NAME_LIMIT, '…'),
            'email' => mb_strtolower(trim($address)),
            'date' => $at->format('Y-m-d'),
            'time' => $at->format('H.i'),
            'subject' => SentEmailArchive::topic($about),
        ];

        $result = (string) preg_replace('/\{\{[^{}]*\}\}/u', '', BulkMailPlaceholders::apply($template, $values));
        $result = trim((string) preg_replace(['/\s*(?:—\s*)+/u', '/\s+/u'], [' — ', ' '], $result), ' —-_');

        return SentEmailArchive::cleanBase($result !== '' ? $result : ($values['name'] ?: 'email'));
    }

    /** Whom a sent email went to, by name: the first of them — a party's, a contact's as corrected, a creditor's — else their address. */
    public static function whom(MatterEmail $email): string
    {
        $address = mb_strtolower((string) (Addresses::emails((array) $email->to)[0] ?? ''));

        if ($address === '') {
            return '';
        }

        return MatterReplyCollector::partyName($address)
            ?? MatterContactDetail::query()->where('matter_id', $email->matter_id)->where('email', $address)->value('name')
            ?? MatterCreditorAddress::query()->where('matter_id', $email->matter_id)->where('email', $address)->first()?->creditor?->name
            ?? $address;
    }

    /**
     * A sent email marked as the first mail to a creditor: its files moved to the first mails folder, named as set.
     * Marked even when it has no files in OneDrive, or no folder is set — its day counts all the same.
     */
    public function mark(MatterEmail $email): void
    {
        $matter = $email->matter;
        $email->update(['first_mail' => true]);
        $folder = $matter ? MatterOneDriveFolders::firstToCreditorsFolderFor($matter, $email->at) : '';

        if ($folder !== '') {
            $this->folders->moveEmailWithin($email, $matter, $folder, name: self::name($matter, self::whom($email), (string) $email->subject, $email->at, (string) (((array) $email->to)[0] ?? '')));
        }
    }

    /** No first mail after all: back with the other sent emails, named as they are. */
    public function unmark(MatterEmail $email): void
    {
        $matter = $email->matter;
        $email->update(['first_mail' => false]);

        if ($matter && (((array) $email->onedrive)['folder'] ?? '') === MatterOneDriveFolders::firstToCreditorsFolderFor($matter, $email->at)) {
            $to = Addresses::emails((array) $email->to);
            $this->folders->moveEmailWithin($email, $matter, MatterOneDriveFolders::sentEmailsFolderFor($matter, $email->at, $to), name: SentEmailArchive::emailName(true, self::whom($email), (string) $email->subject, $email->at));
        }
    }

    /**
     * When the first mail went to each address of the matter: address => the earliest — a sent email marked so, or
     * one of a campaign ticked so.
     *
     * @return array<string, Carbon>
     */
    public static function datesByAddress(Matter $matter): array
    {
        $dates = [];
        $note = function (array $addresses, mixed $at) use (&$dates): void {
            if ($at instanceof Carbon) {
                foreach (array_map('mb_strtolower', Addresses::emails($addresses)) as $address) {
                    $dates[$address] = isset($dates[$address]) && $dates[$address]->lte($at) ? $dates[$address] : $at;
                }
            }
        };

        foreach ($matter->emails()->where('direction', MatterEmail::SENT)->where('first_mail', true)->whereNotNull('at')->get(['to', 'at']) as $email) {
            $note((array) $email->to, $email->at);
        }

        BulkMailRecipient::query()->whereNotNull('sent_at')
            ->whereIn('campaign_id', BulkMailCampaign::query()->where('matter_id', $matter->getKey())->where('first_mail', true)->select('id'))
            ->get(['email', 'sent_at'])
            ->each(fn (BulkMailRecipient $recipient) => $note((array) $recipient->email, $recipient->sent_at));

        return $dates;
    }
}
