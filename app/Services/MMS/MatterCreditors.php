<?php

namespace App\Services\MMS;

use App\Enums\BulkMailRecipientStatus;
use App\Models\BulkMailCampaign;
use App\Models\Matter;
use App\Models\MatterCreditor;
use App\Models\MatterEmail;
use App\Support\Addresses;
use App\Support\ArabicSearch;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * A matter's creditors (a bankruptcy's — kept for a matter whose type has them): the emails come in from them and
 * their agents moved to their folder once they are known, the list exported for a bulk mail to all of them, and a
 * bulk mail's recipients list merged back into it.
 */
final class MatterCreditors
{
    /** The bulk mail recipients import's columns: "name" and "email" mapped by themselves, the rest placeholders ({{claim_amount}}). */
    public const BULK_MAIL_HEADINGS = ['name', 'email', 'agent', 'phone', 'claim_amount'];

    public function __construct(private readonly MatterOneDriveFolders $folders) {}

    /** Whether the matter keeps a list of creditors: its type does. */
    public static function kept(Matter $matter): bool
    {
        return (bool) $matter->loadMissing('type')->type?->has_creditors;
    }

    /**
     * The emails that came in on the matter from these addresses — those not in a periodic report — moved to the
     * folder of their creditor, where those from them go now. How many moved.
     *
     * @param  list<string>  $addresses
     */
    public function refile(Matter $matter, array $addresses): int
    {
        $addresses = array_map('mb_strtolower', Addresses::emails($addresses));
        $moved = 0;

        $emails = $matter->emails()->where('direction', MatterEmail::RECEIVED)->get()
            ->filter(fn (MatterEmail $email): bool => in_array(MatterEmail::addressOf((string) $email->from), $addresses, true)
                && (((array) $email->onedrive)['report'] ?? null) === null);

        foreach ($emails as $email) {
            $from = MatterEmail::addressOf((string) $email->from);
            $folder = MatterOneDriveFolders::receivedEmailsFolderFor($matter, $email->at, ReceivedEmailFiling::CREDITOR, $from);

            if ($folder !== MatterOneDriveFolders::emailFolder($email, $matter)) {
                $moved += $this->folders->moveEmailWithin($email, $matter, $folder) ? 1 : 0;
            }
        }

        return $moved;
    }

    /**
     * A creditor as a bulk mail's recipients list has it, merged into the matter's list: the one with any of its
     * addresses, else of its name (written either way: أ/ا, ة/ه …), else a new one. Its addresses the matter does not
     * have yet added as its own — those another creditor has left with it — its emails come in moved to its folder;
     * its claim updated when the list gives another; its phones when it has none. Its name kept as it is. The
     * creditor; null when the line has no name and no address.
     *
     * @param  list<string>  $emails
     * @param  list<string>  $phones
     */
    public function merge(Matter $matter, string $name, array $emails, ?string $claim = null, array $phones = []): ?MatterCreditor
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));
        $emails = array_values(array_unique(array_map('mb_strtolower', Addresses::emails($emails))));

        if ($name === '' && $emails === []) {
            return null;
        }

        $known = $matter->creditorAddresses()->whereIn('email', $emails ?: [''])->get(['email', 'matter_creditor_id']);
        $creditor = $known->isNotEmpty()
            ? $matter->creditors()->find($known->first()->matter_creditor_id)
            : $matter->creditors()->get(['id', 'name', 'phones', 'claim_amount'])->first(fn (MatterCreditor $creditor): bool => self::sameName($creditor->name, $name));
        $creditor ??= $matter->creditors()->create(['name' => $name !== '' ? $name : $emails[0]]);

        $amount = self::amount($claim);
        $creditor->fill([
            'claim_amount' => $amount ?? $creditor->claim_amount,
            'phones' => $creditor->phones ?: (array_values(array_filter(array_map('trim', $phones))) ?: null),
        ])->save();

        $new = array_values(array_diff($emails, $known->pluck('email')->all()));
        foreach ($new as $email) {
            MatterCreditor::assign($matter, (int) $creditor->getKey(), null, $email);
        }

        if ($new !== []) {
            $this->refile($matter, $new);
        }

        return $creditor;
    }

    /** One name written two ways: letter case, spaces and أ/ا, ة/ه, ى/ي … aside. */
    private static function sameName(string $a, string $b): bool
    {
        $plain = fn (string $name): string => mb_strtolower(ArabicSearch::normalize(trim((string) preg_replace('/\s+/u', ' ', $name))));

        return $plain($a) !== '' && $plain($a) === $plain($b);
    }

    /** An amount as a list writes it — "250,000", "٢٥٠٬٠٠٠", "AED 250000.50" — or null for none. */
    public static function amount(?string $value): ?string
    {
        $value = strtr(trim((string) $value), ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9', '٫' => '.']);
        $value = (string) preg_replace('/[^\d.]/u', '', $value);

        return is_numeric($value) ? number_format((float) $value, 2, '.', '') : null;
    }

    /**
     * One line a creditor with an address — its own or its agent's — as the bulk mail recipients import reads it
     * (BULK_MAIL_HEADINGS): the email to the creditor and its agents alike.
     *
     * @return list<list<string>>
     */
    public static function bulkMailRows(Matter $matter): array
    {
        return array_map(fn (array $line): array => [$line['name'], implode(', ', $line['email']), $line['agent'], $line['phone'], $line['claim_amount']], self::lines($matter));
    }

    /**
     * The matter's creditors as a bulk mail's recipients — those not on the campaign yet (none of their addresses
     * there): to the creditor and its agents alike, its agent, phone and claim its placeholders ({{claim_amount}}).
     * How many were added.
     */
    public static function addToCampaign(BulkMailCampaign $campaign, Matter $matter): int
    {
        $there = $campaign->recipients()->pluck('email')->flatMap(fn ($emails): array => array_map('mb_strtolower', Addresses::emails((array) $emails)))->flip();
        $added = 0;

        foreach (self::lines($matter) as $line) {
            if (collect($line['email'])->contains(fn (string $email): bool => $there->has($email))) {
                continue;
            }

            $campaign->recipients()->create([
                'name' => $line['name'],
                'email' => $line['email'],
                'placeholders' => array_filter(['agent' => $line['agent'], 'phone' => $line['phone'], 'claim_amount' => $line['claim_amount']], fn (string $value): bool => $value !== ''),
                'status' => BulkMailRecipientStatus::Pending,
            ]);
            $added++;
        }

        $campaign->update(['total_recipients' => $campaign->recipients()->count()]);

        return $added;
    }

    /**
     * Each creditor with an address, as a bulk mail has it.
     *
     * @return list<array{name: string, email: list<string>, agent: string, phone: string, claim_amount: string}>
     */
    private static function lines(Matter $matter): array
    {
        return $matter->creditors()->with('addresses')->orderBy('name')->get()
            ->filter(fn (MatterCreditor $creditor): bool => $creditor->addresses->isNotEmpty())
            ->map(fn (MatterCreditor $creditor): array => [
                'name' => $creditor->name,
                // Its own first.
                'email' => $creditor->addresses->sortBy('agent')->pluck('email')->values()->all(),
                'agent' => $creditor->addresses->where('agent', true)->pluck('agent_name')->filter()->unique()->implode('، '),
                'phone' => implode(', ', (array) $creditor->phones),
                'claim_amount' => (string) ($creditor->claim_amount ?? ''),
            ])
            ->values()
            ->all();
    }

    public static function bulkMailDownload(Matter $matter): BinaryFileResponse
    {
        return MatterContactsExport::sheet(__('Creditors').' '.$matter->number.'-'.$matter->year.'.xlsx', self::BULK_MAIL_HEADINGS, self::bulkMailRows($matter));
    }
}
