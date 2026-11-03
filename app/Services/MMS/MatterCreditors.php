<?php

namespace App\Services\MMS;

use App\Enums\BulkMailRecipientStatus;
use App\Models\BulkMailCampaign;
use App\Models\Matter;
use App\Models\MatterContact;
use App\Models\MatterCreditor;
use App\Models\MatterCreditorAddress;
use App\Models\MatterEmail;
use App\Models\MatterParty;
use App\Models\Party;
use App\Support\Addresses;
use App\Support\ArabicSearch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
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
            // Not one put in OneDrive by hand: it stays where it was put.
            ->filter(fn (MatterEmail $email): bool => in_array(MatterEmail::addressOf((string) $email->from), $addresses, true)
                && $email->matched_by !== MatterEmail::BY_ONEDRIVE
                && (((array) $email->onedrive)['report'] ?? null) === null);

        foreach ($emails as $email) {
            $from = MatterEmail::addressOf((string) $email->from);
            // Where the creditor's go — without the day's number ({{serial}}): one already under it stays where it is.
            $under = MatterOneDriveFolders::receivedEmailsFolderFor($matter, $email->at, ReceivedEmailFiling::CREDITOR, $from, nextSerial: false);
            $where = MatterOneDriveFolders::emailFolder($email, $matter);

            if ($under !== '' && $where !== $under && ! str_starts_with(mb_strtolower($where).'/', mb_strtolower($under).'/')) {
                $moved += $this->folders->moveEmailWithin($email, $matter, MatterOneDriveFolders::receivedEmailsFolderFor($matter, $email->at, ReceivedEmailFiling::CREDITOR, $from)) ? 1 : 0;
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
     * @param  ?string  $idNumber  its trade licence or ID number — known by it when it has it already
     * @param  array<string, string>  $custom  the values of its own columns (Type::creditorColumns()), those given
     */
    public function merge(Matter $matter, string $name, array $emails, ?string $claim = null, array $phones = [], ?string $idNumber = null, array $custom = []): ?MatterCreditor
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));
        $emails = array_values(array_unique(array_map('mb_strtolower', Addresses::emails($emails))));
        $idNumber = trim((string) $idNumber) ?: null;

        if ($name === '' && $emails === [] && $idNumber === null) {
            return null;
        }

        $known = $matter->creditorAddresses()->whereIn('email', $emails ?: [''])->get(['email', 'matter_creditor_id']);
        $creditor = $known->isNotEmpty()
            ? $matter->creditors()->find($known->first()->matter_creditor_id)
            : ($idNumber !== null ? $matter->creditors()->where('id_number', $idNumber)->first() : null)
                ?? $matter->creditors()->get(['id', 'name', 'phones', 'claim_amount'])->first(fn (MatterCreditor $creditor): bool => self::sameName($creditor->name, $name));
        $creditor ??= $matter->creditors()->create(['name' => $name !== '' ? $name : ($emails[0] ?? $idNumber)]);

        $amount = self::amount($claim);
        $creditor->fill([
            'claim_amount' => $amount ?? $creditor->claim_amount,
            'phones' => $creditor->phones ?: (array_values(array_filter(array_map('trim', $phones))) ?: null),
            'id_number' => $creditor->id_number ?: $idNumber,
            'custom' => array_filter([...(array) $creditor->custom, ...array_map(fn ($value): string => trim((string) $value), $custom)], fn (string $value): bool => $value !== '') ?: null,
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

    /**
     * The Contacts tab's rows given, each made a creditor (merge()) by its name, its own addresses and phones — a
     * party stays a party too, and is then both, its representatives on the matter linked as its agents. One a
     * creditor or an agent already, or with no address, is left as it is. How many were made, and how many
     * representatives' addresses were linked.
     *
     * @param  iterable<array<string, mixed>>  $rows  as MatterContactsExport::rows() gives them
     * @return array{0: int, 1: int}
     */
    public function makeCreditors(Matter $matter, iterable $rows): array
    {
        $rows = collect($rows)->filter(fn ($row): bool => is_array($row) && ($row['creditor_id'] ?? null) === null && ($row['own'] ?? []) !== []);
        // A representative ticked along with the party it stands for: linked as its agent when the party is made
        // a creditor, not made a creditor of its own.
        $partyIds = $rows->pluck('party_id')->filter()->map(fn ($id): int => (int) $id)->all();
        $representedBy = self::representedBy($matter, $partyIds);
        $made = 0;
        $linked = [];

        foreach ($rows as $row) {
            $partyId = filled($row['party_id'] ?? null) ? (int) $row['party_id'] : null;

            if ($partyId !== null && isset($representedBy[$partyId]) && in_array($representedBy[$partyId], $partyIds, true)) {
                continue;
            }

            $creditor = $this->merge($matter, (string) ($row['name'] ?? ''), (array) $row['own'], null, array_map('trim', explode(',', (string) ($row['phones'] ?? ''))));

            if ($creditor === null) {
                continue;
            }

            $made++;

            if ($partyId !== null) {
                $linked = [...$linked, ...$this->linkRepresentatives($matter, $creditor, $partyId)];
            }
        }

        if ($linked !== []) {
            $this->refile($matter, $linked);
        }

        return [$made, count($linked)];
    }

    /**
     * A party's representatives on the matter (its lawyers), each by its party id: its name and its addresses.
     *
     * @return array<int, array{name: string, emails: list<string>}>
     */
    public static function representativesOf(Matter $matter, int $partyId): array
    {
        return MatterParty::query()->where('matter_id', $matter->getKey())->where('role', 'representative')
            ->whereHas('parentMatterParty', fn (Builder $query) => $query->where('party_id', $partyId))
            ->with('party:id,name,email')->get()
            ->filter(fn (MatterParty $representative): bool => $representative->party !== null)
            ->mapWithKeys(fn (MatterParty $representative): array => [(int) $representative->party_id => [
                'name' => (string) $representative->party->name,
                'emails' => Addresses::emails(Party::values($representative->party->email)),
            ]])
            ->all();
    }

    /**
     * Of the parties given, those that are representatives on the matter: each by its party id, the party it
     * stands for.
     *
     * @param  list<int>  $partyIds
     * @return array<int, int>
     */
    private static function representedBy(Matter $matter, array $partyIds): array
    {
        if ($partyIds === []) {
            return [];
        }

        return MatterParty::query()->where('matter_id', $matter->getKey())->where('role', 'representative')->whereIn('party_id', $partyIds)
            ->with('parentMatterParty:id,party_id')->get()
            ->filter(fn (MatterParty $representative): bool => $representative->parentMatterParty?->party_id !== null)
            ->mapWithKeys(fn (MatterParty $representative): array => [(int) $representative->party_id => (int) $representative->parentMatterParty->party_id])
            ->all();
    }

    /**
     * A party made a creditor: its representatives on the matter linked as the creditor's agents, by their names
     * and addresses — an address a creditor of the matter has already is left where it is. The addresses linked,
     * for their emails to move (refile()).
     *
     * @return list<string>
     */
    public function linkRepresentatives(Matter $matter, MatterCreditor $creditor, int $partyId): array
    {
        $representatives = self::representativesOf($matter, $partyId);

        if ($representatives === []) {
            return [];
        }

        $taken = $matter->creditorAddresses()->pluck('email')->map(fn ($email): string => mb_strtolower((string) $email))->all();
        $linked = [];

        foreach ($representatives as $representative) {
            foreach ($representative['emails'] as $email) {
                $email = mb_strtolower($email);

                if (in_array($email, $taken, true)) {
                    continue;
                }

                MatterCreditor::assign($matter, (int) $creditor->getKey(), null, $email, true, $representative['name']);
                $taken[] = $linked[] = $email;
            }
        }

        return $linked;
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
        $headings = self::bulkMailHeadings($matter);

        return array_map(fn (array $line): array => array_map(fn (string $heading): string => is_array($line[$heading] ?? null) ? implode(', ', $line[$heading]) : (string) ($line[$heading] ?? ''), $headings), self::lines($matter));
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
                'placeholders' => array_filter(array_diff_key($line, ['name' => 1, 'email' => 1]), fn (string $value): bool => $value !== ''),
                'status' => BulkMailRecipientStatus::Pending,
            ]);
            $added++;
        }

        $campaign->update(['total_recipients' => $campaign->recipients()->count()]);

        return $added;
    }

    /**
     * The columns of the creditors' sheet (bulkMailDownload()): the usual, then the creditor's number, its licence
     * or ID, and the type's own columns — each a placeholder in a bulk mail to them.
     *
     * @return list<string>
     */
    public static function bulkMailHeadings(Matter $matter): array
    {
        return [...self::BULK_MAIL_HEADINGS, 'number', 'id_number', ...self::ownColumns($matter)];
    }

    /**
     * The columns the matter's creditors have of their own (its type's).
     *
     * @return list<string>
     */
    public static function ownColumns(Matter $matter): array
    {
        return $matter->loadMissing('type')->type?->creditorColumns() ?? [];
    }

    /**
     * Each creditor with an address, as a bulk mail has it: the usual columns (BULK_MAIL_HEADINGS), its number, its
     * licence or ID, and its own columns by their names.
     *
     * @return list<array<string, mixed>>
     */
    private static function lines(Matter $matter): array
    {
        $columns = self::ownColumns($matter);

        return $matter->creditors()->with('addresses')->orderBy('number')->orderBy('name')->get()
            ->filter(fn (MatterCreditor $creditor): bool => $creditor->addresses->isNotEmpty())
            ->map(fn (MatterCreditor $creditor): array => [
                'name' => $creditor->name,
                // Its own first.
                'email' => $creditor->addresses->sortBy('agent')->pluck('email')->values()->all(),
                'agent' => $creditor->addresses->where('agent', true)->pluck('agent_name')->filter()->unique()->implode('، '),
                'phone' => implode(', ', (array) $creditor->phones),
                'claim_amount' => (string) ($creditor->claim_amount ?? ''),
                'number' => (string) ($creditor->number ?? ''),
                'id_number' => (string) ($creditor->id_number ?? ''),
                ...array_combine($columns, array_map(fn (string $column): string => (string) $creditor->customValue($column), $columns)),
            ])
            ->values()
            ->all();
    }

    public static function bulkMailDownload(Matter $matter): BinaryFileResponse
    {
        return MatterContactsExport::sheet(__('Creditors').' '.$matter->number.'-'.$matter->year.'.xlsx', self::bulkMailHeadings($matter), self::bulkMailRows($matter));
    }

    /**
     * An email that came in from a creditor (or its agent): whoever it copied in — outside the office, no party, no
     * other creditor's — is the creditor's too, so their emails go to its folder from now on. How many addresses
     * were added. Nothing for an email of anyone else's.
     */
    public function learnCopied(MatterEmail $email, string $side): int
    {
        if ($side !== ReceivedEmailFiling::CREDITOR || ! $email->matter) {
            return 0;
        }

        $matter = $email->matter;
        $address = MatterCreditorAddress::of($matter, MatterEmail::addressOf((string) $email->from));

        if ($address?->creditor === null) {
            return 0;
        }

        $theirs = $matter->creditorAddresses()->pluck('email')->map(fn ($email): string => mb_strtolower((string) $email))->all();
        $added = 0;

        foreach (array_map('mb_strtolower', Addresses::emails([...(array) $email->to, ...(array) $email->cc])) as $copied) {
            if (in_array($copied, $theirs, true) || MatterContact::isOurs($copied) || MatterContact::partiesWith($copied)->isNotEmpty()) {
                continue;
            }

            MatterCreditor::assign($matter, (int) $address->creditor->getKey(), null, $copied);
            $theirs[] = $copied;
            $added++;
        }

        return $added;
    }

    /**
     * A creditor of another matter, on this one: a new creditor with its name, licence or ID, phones and own
     * columns, and its addresses (its agents' too) — those not another's here already. The one made.
     */
    public function copy(Matter $matter, MatterCreditor $source): MatterCreditor
    {
        return DB::transaction(function () use ($matter, $source): MatterCreditor {
            $creditor = $matter->creditors()->create([
                'name' => $source->name,
                'id_number' => $source->id_number,
                'phones' => $source->phones,
                'claim_amount' => null,
                'custom' => $source->custom,
            ]);
            $taken = $matter->creditorAddresses()->pluck('email')->map(fn ($email): string => mb_strtolower((string) $email))->all();

            foreach ($source->addresses()->get() as $address) {
                if (! in_array(mb_strtolower((string) $address->email), $taken, true)) {
                    MatterCreditor::assign($matter, (int) $creditor->getKey(), null, (string) $address->email, (bool) $address->agent, $address->agent_name);
                }
            }

            return $creditor;
        });
    }

    /**
     * Creditors of other matters that may be this one: by a name, a licence or ID, or an address — for "the same
     * creditor on another matter" (CreditorFields). id => "name — matter".
     *
     * @return array<int, string>
     */
    public static function elsewhere(Matter $matter, string $search, int $limit = 20): array
    {
        $search = trim($search);

        if ($search === '') {
            return [];
        }

        $like = '%'.addcslashes($search, '%_\\').'%';

        return MatterCreditor::query()
            ->where('matter_id', '!=', $matter->getKey())
            ->where(fn (Builder $query) => $query
                ->where('name', 'like', $like)
                ->orWhere('id_number', 'like', $like)
                ->orWhereHas('addresses', fn (Builder $q) => $q->where('email', 'like', $like)))
            ->with('matter:id,year,number')
            ->latest('id')
            ->limit($limit)
            ->get()
            ->mapWithKeys(fn (MatterCreditor $creditor): array => [(int) $creditor->getKey() => $creditor->name.($creditor->id_number ? ' ('.$creditor->id_number.')' : '').' — '.$creditor->matter?->year.'/'.$creditor->matter?->number])
            ->all();
    }

    /** The creditor of another matter this address is known to be — the latest; null when it is no creditor's anywhere. */
    public static function knownElsewhere(Matter $matter, string $address): ?MatterCreditor
    {
        $address = mb_strtolower(trim($address));

        if ($address === '') {
            return null;
        }

        return MatterCreditorAddress::query()->where('email', $address)->where('matter_id', '!=', $matter->getKey())->latest('id')->first()?->creditor;
    }

    /** Of the matter's emails that came in: those from no creditor's address (to assign). */
    public static function withoutCreditor(Builder $query): Builder
    {
        $from = $query->getQuery()->getGrammar()->wrap('matter_emails.from');
        $pattern = DB::connection()->getDriverName() === 'sqlite' ? "'%' || a.email || '%'" : "concat('%', a.email, '%')";

        return $query
            ->where('matter_emails.direction', MatterEmail::RECEIVED)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('matter_creditor_addresses as a')->whereColumn('a.matter_id', 'matter_emails.matter_id')->whereRaw("lower({$from}) like {$pattern}"));
    }

    /**
     * When the first email came in on the matter from each creditor (its own address or its agents'): creditor id
     * => when — for the legal deadlines of the claims.
     *
     * @return array<int, Carbon>
     */
    public static function firstEmailsAt(Matter $matter): array
    {
        $creditorOf = $matter->creditorAddresses()->get(['email', 'matter_creditor_id'])->mapWithKeys(fn (MatterCreditorAddress $address): array => [mb_strtolower((string) $address->email) => (int) $address->matter_creditor_id])->all();

        if ($creditorOf === []) {
            return [];
        }

        $firsts = [];

        foreach ($matter->emails()->where('direction', MatterEmail::RECEIVED)->orderBy('at')->get(['from', 'at']) as $email) {
            $creditor = $creditorOf[MatterEmail::addressOf((string) $email->from)] ?? null;

            if ($creditor !== null && ! isset($firsts[$creditor]) && $email->at) {
                $firsts[$creditor] = $email->at;
            }
        }

        return $firsts;
    }

    /** The headings a creditors' sheet may use for each of the usual columns — in English or Arabic, as a list writes them. */
    private const SHEET_HEADINGS = [
        'name' => ['name', 'creditor', 'الاسم', 'اسم الدائن', 'الدائن'],
        'email' => ['email', 'emails', 'e-mail', 'البريد', 'البريد الإلكتروني', 'الايميل', 'الإيميل'],
        'agent' => ['agent', 'lawyer', 'الوكيل', 'المحامي'],
        'phone' => ['phone', 'phones', 'mobile', 'الهاتف', 'الجوال', 'رقم الهاتف'],
        'claim_amount' => ['claim_amount', 'claim', 'amount', 'المطالبة', 'قيمة المطالبة', 'المبلغ'],
        'id_number' => ['id_number', 'id', 'licence', 'license', 'trade licence', 'trade license', 'الرخصة', 'رقم الرخصة', 'الهوية', 'رقم الهوية'],
    ];

    /**
     * A creditors' sheet (Excel or CSV) read into the matter's list: the first row its headings — name, email,
     * agent, phone, claim_amount, id_number, in English or Arabic — and any other heading that is one of the type's
     * own columns; the rest ignored. Each line merged (merge()): known by its address, its licence or ID, or its
     * name. How many lines were taken.
     */
    public function importSheet(Matter $matter, string $path): int
    {
        $reader = str_ends_with(mb_strtolower($path), '.csv') ? new CsvReader : new XlsxReader;
        $reader->open($path);
        $columns = self::ownColumns($matter);
        $map = null;
        $taken = 0;

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $cells = array_map(fn ($cell): string => trim((string) $cell), $row->toArray());

                    if ($map === null) {
                        $map = self::sheetColumns($cells, $columns);

                        continue;
                    }

                    $value = fn (string $key): ?string => isset($map[$key]) ? ($cells[$map[$key]] ?? null) : null;
                    $custom = [];
                    foreach ($columns as $column) {
                        if (($own = $value('custom:'.$column)) !== null && $own !== '') {
                            $custom[$column] = $own;
                        }
                    }

                    $creditor = $this->merge(
                        $matter,
                        (string) $value('name'),
                        preg_split('/[\s,;،]+/u', (string) $value('email')) ?: [],
                        $value('claim_amount'),
                        preg_split('/[\s,;،]+/u', (string) $value('phone')) ?: [],
                        $value('id_number'),
                        $custom,
                    );

                    if ($creditor !== null) {
                        $taken++;

                        if (($agent = (string) $value('agent')) !== '') {
                            foreach ($creditor->addresses()->where('agent', false)->whereNull('agent_name')->get() as $address) {
                                // The addresses the line gave beyond the first are its agent's, named as the line names it.
                                if ($address->email !== mb_strtolower(trim((string) (preg_split('/[\s,;،]+/u', (string) $value('email'))[0] ?? '')))) {
                                    $address->update(['agent' => true, 'agent_name' => $agent]);
                                }
                            }
                        }
                    }
                }

                break; // The first sheet alone.
            }
        } finally {
            $reader->close();
        }

        return $taken;
    }

    /**
     * Which column of a sheet holds what: key => column index — the usual by their headings (SHEET_HEADINGS), the
     * type's own columns by their names ("custom:<name>").
     *
     * @param  list<string>  $headings
     * @param  list<string>  $columns
     * @return array<string, int>
     */
    private static function sheetColumns(array $headings, array $columns): array
    {
        $plain = fn (string $heading): string => mb_strtolower(ArabicSearch::normalize(trim(str_replace(['_', '-'], ' ', $heading))));
        $map = [];

        foreach ($headings as $index => $heading) {
            $key = $plain($heading);

            foreach (self::SHEET_HEADINGS as $field => $names) {
                if (! isset($map[$field]) && in_array($key, array_map($plain, $names), true)) {
                    $map[$field] = $index;

                    continue 2;
                }
            }

            foreach ($columns as $column) {
                if ($plain($column) === $key) {
                    $map['custom:'.$column] = $index;
                }
            }
        }

        return $map;
    }
}
