<?php

namespace App\Services\MMS;

use App\Models\BulkMailCampaign;
use App\Models\Matter;
use App\Models\MatterContact;
use App\Models\MatterContactDetail;
use App\Models\MatterCreditor;
use App\Models\MatterCreditorAddress;
use App\Models\MatterEmail;
use App\Models\MatterParty;
use App\Models\Party;
use App\Models\Type;
use App\Support\Addresses;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Everyone a matter has dealt with, and how to reach them, as one Excel
 * sheet: its parties and their representatives, attendees and external
 * entities, its letters' and minutes' recipients, its bulk mail's
 * recipients (creditors …), anyone else its emails went to or came from —
 * each once, however many of these they are —
 * with the addresses they brought in by replying (MatterContact) and,
 * for those who are no party, their details as corrected (MatterContactDetail).
 */
class MatterContactsExport
{
    /** @var array<string, array{name: string, capacity: list<string>, for: list<string>, source: list<string>, phones: list<string>, emails: list<string>, theirs: list<string>}> */
    private array $rows = [];

    /** @var array<string, string> address => its row */
    private array $byEmail = [];

    public function __construct(private readonly Matter $matter) {}

    public static function download(Matter $matter): BinaryFileResponse
    {
        // Their claims, on a matter that has creditors.
        $claims = $matter->creditors()->exists();

        return self::sheet(
            __('Contacts').' '.$matter->number.'-'.$matter->year.'.xlsx',
            [...self::headings(), ...($claims ? [__('Claim amount')] : [])],
            array_map(fn (array $row): array => [$row['name'], $row['capacity'], $row['for'], $row['source'], $row['phones'], $row['emails'], $row['theirs'], ...($claims ? [$row['claim']] : [])], (new self($matter))->rows()),
        );
    }

    /**
     * Rows as an Excel sheet, to download — right to left in Arabic.
     *
     * @param  list<string>  $headings
     * @param  iterable<list<mixed>>  $rows
     */
    public static function sheet(string $fileName, array $headings, iterable $rows): BinaryFileResponse
    {
        $path = storage_path('app/temp/'.Str::uuid().'.xlsx');

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        $writer = new Writer;
        $writer->openToFile($path);

        if (app()->getLocale() === 'ar') {
            $writer->getCurrentSheet()->setSheetView((new SheetView)->setRightToLeft(true));
        }

        $writer->addRow(Row::fromValues($headings, (new Style)->setFontBold()));

        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues($row));
        }

        $writer->close();

        return response()->download($path, $fileName)->deleteFileAfterSend();
    }

    /**
     * @return list<string>
     */
    public static function headings(): array
    {
        return [__('Name'), __('Capacity'), __('On behalf of'), __('Source'), __('Phones'), __('Emails'), __('Other emails (from their replies)')];
    }

    /**
     * One line each: name, capacity, on behalf of, source, phones, emails,
     * the others they brought in — and, to change those, the line's key,
     * party and own addresses; a creditor's (or its agent's) creditor, and
     * its claim.
     *
     * @return list<array{key: string, name: string, capacity: string, for: string, source: string, phones: string, emails: string, theirs: string, party_id: ?int, own: list<string>, creditor_id: ?int, agent: bool, claim: ?string}>
     */
    public function rows(): array
    {
        $this->parties();
        $this->creditors();
        $this->letters();
        $this->minutes();
        $this->bulkMail();
        $this->emailed();
        $this->theirs();
        $this->corrected();

        return array_values(array_map(fn (string $key, array $row): array => [
            'key' => $key,
            'name' => $row['name'],
            'capacity' => implode('، ', array_unique($row['capacity'])),
            'for' => implode('، ', array_unique($row['for'])),
            'source' => implode('، ', array_unique($row['source'])),
            'phones' => implode(', ', array_unique($row['phones'])),
            'emails' => implode(', ', Addresses::emails($row['emails'])),
            'theirs' => implode(', ', Addresses::without(Addresses::emails($row['theirs']), $row['emails'])),
            'party_id' => str_starts_with($key, 'p:') ? (int) substr($key, 2) : null,
            'own' => Addresses::emails($row['emails']),
            'creditor_id' => $row['creditor'] ?? null,
            'agent' => (bool) ($row['agent'] ?? false),
            'claim' => $row['claim'] ?? null,
        ], array_keys($this->rows), $this->rows));
    }

    /** Its creditors — each with its own addresses — and their agents, each agent once with the addresses it writes from. */
    private function creditors(): void
    {
        foreach ($this->matter->creditors()->with('addresses')->orderBy('name')->get() as $creditor) {
            /** @var MatterCreditor $creditor */
            $key = $this->add('c:'.$creditor->getKey(), $creditor->name, $creditor->addresses->where('agent', false)->pluck('email')->all(), (array) $creditor->phones, __('Creditors'), __('Creditor'));
            $this->rows[$key]['creditor'] = (int) $creditor->getKey();
            $this->rows[$key]['claim'] = $creditor->claim_amount;

            $agents = $creditor->addresses->where('agent', true)->groupBy(fn (MatterCreditorAddress $address): string => mb_strtolower(trim((string) $address->agent_name)) ?: $address->email);

            foreach ($agents as $addresses) {
                $first = $addresses->first();
                $key = $this->add('a:'.$first->getKey(), (string) ($first->agent_name ?: $first->email), $addresses->pluck('email')->all(), [], __('Creditors'), __("Creditor's agent"), $creditor->name);
                $this->rows[$key]['creditor'] = (int) $creditor->getKey();
                $this->rows[$key]['agent'] = true;
            }
        }
    }

    /** Its parties, representatives, attendees, external entities — not its experts. */
    private function parties(): void
    {
        $rows = $this->matter->matterParties()
            ->with(['party:id,name,email,phone', 'parentMatterParty.party:id,name'])
            ->where(fn ($q) => $q->whereNull('role')->orWhere('role', '!=', 'expert'))
            ->get();
        $type = $this->matter->type_id ? Type::remembered($this->matter->type_id) : null;

        foreach ($rows as $row) {
            /** @var MatterParty $row */
            if (! $row->party) {
                continue;
            }

            $capacity = $row->role === 'party' || in_array($row->type, Type::SIDES, true)
                ? Type::sideLabel($type, $row->type)
                : (Party::roleOptions()[$row->role] ?? (string) $row->role);

            $this->add('p:'.$row->party_id, $row->party->name, Party::values($row->party->email), Party::values($row->party->phone), __('Matter party'), $capacity, $row->parentMatterParty?->party?->name);
        }
    }

    /** Whom its letters went to, and their representatives. */
    private function letters(): void
    {
        foreach ($this->matter->letters()->with('recipients')->get() as $letter) {
            foreach ($letter->recipients as $recipient) {
                $key = $recipient->recipient_id ? 'p:'.$recipient->recipient_id : null;
                $this->add($key, (string) $recipient->name, Addresses::emails($recipient->emails ?: [$recipient->email]), (array) ($recipient->phones ?? []), __('Letter'), (string) $recipient->role);

                foreach ((array) ($recipient->representatives ?? []) as $representative) {
                    $this->add(null, (string) ($representative['name'] ?? ''), (array) ($representative['emails'] ?? []), (array) ($representative['phones'] ?? []), __('Letter'), __('Representative'), (string) $recipient->name);
                }
            }
        }
    }

    /** Who attended its meetings. */
    private function minutes(): void
    {
        foreach ($this->matter->minutes()->get(['id', 'attendees']) as $minutes) {
            foreach ((array) $minutes->attendees as $attendee) {
                if (! is_array($attendee) || blank($attendee['name'] ?? null)) {
                    continue;
                }

                $key = filled($attendee['party_id'] ?? null) ? 'p:'.$attendee['party_id'] : null;
                $this->add($key, (string) $attendee['name'], [$attendee['email'] ?? null], [$attendee['phone'] ?? null], __('Minutes'), (string) ($attendee['capacity'] ?? ''));
            }
        }
    }

    /** Whom its bulk mail went to — creditors and the like — and whom it copied. */
    private function bulkMail(): void
    {
        BulkMailCampaign::query()->where('matter_id', $this->matter->getKey())->with('recipients:id,campaign_id,name,email,cc_emails')->get(['id', 'name'])
            ->each(function (BulkMailCampaign $campaign) {
                foreach ($campaign->recipients as $recipient) {
                    $emails = Addresses::emails((array) $recipient->email);
                    $key = $this->add(null, (string) ($recipient->name ?: ($emails[0] ?? '')), $emails, [], __('Bulk mail: :name', ['name' => $campaign->name]), '');
                    $this->rows[$key]['theirs'] = [...$this->rows[$key]['theirs'], ...(array) ($recipient->cc_emails ?? [])];
                }
            });
    }

    /** Anyone else an email of the matter went to, or came from. */
    private function emailed(): void
    {
        foreach ($this->matter->emails()->get(['id', 'direction', 'from', 'to']) as $email) {
            $addresses = $email->direction === MatterEmail::SENT ? (array) $email->to : [MatterContact::split((string) $email->from)];

            foreach ($addresses as $address) {
                [$name, $address] = is_array($address) ? $address : ['', (string) $address];

                if ($address !== '' && ! isset($this->byEmail[mb_strtolower($address)]) && ! MatterContact::isOurs($address)) {
                    $this->add(null, $name ?: (MatterReplyCollector::partyName($address) ?? $address), [$address], [], $email->direction === MatterEmail::SENT ? __('Email sent') : __('Email received'), '');
                }
            }
        }
    }

    /** The addresses each brought in by replying — on their line, or one of their own. */
    private function theirs(): void
    {
        foreach ($this->matter->contacts()->get() as $contact) {
            /** @var MatterContact $contact */
            $key = ($contact->party_id && isset($this->rows['p:'.$contact->party_id])) ? 'p:'.$contact->party_id : ($this->byEmail[$contact->owner_email] ?? null);
            $key ??= $this->add(null, (string) ($contact->owner_name ?: $contact->owner_email), [$contact->owner_email], [], __('Reply'), '');

            $this->rows[$key]['theirs'][] = $contact->email;
        }
    }

    /** Those who are no party, as corrected on the matter's Contacts tab: their name, capacity and phones. */
    private function corrected(): void
    {
        foreach ($this->matter->contactDetails()->get() as $detail) {
            /** @var MatterContactDetail $detail */
            $key = $this->byEmail[$detail->email] ?? null;

            // A party's on the party; a creditor's on its creditor.
            if ($key === null || str_starts_with($key, 'p:') || isset($this->rows[$key]['creditor'])) {
                continue;
            }

            $this->rows[$key]['name'] = $detail->name ?: $this->rows[$key]['name'];
            $this->rows[$key]['capacity'] = array_values(array_filter([trim((string) $detail->capacity)]));
            $this->rows[$key]['phones'] = Party::values($detail->phones);
        }
    }

    /**
     * Someone on their line — the party's, else the one with any of these
     * addresses — or a new one. Their line.
     *
     * @param  list<mixed>  $emails
     * @param  list<mixed>  $phones
     */
    private function add(?string $key, string $name, array $emails, array $phones, string $source, string $capacity, ?string $for = null): string
    {
        $emails = Addresses::emails($emails);
        $phones = array_values(array_filter(array_map(fn ($p) => trim((string) $p), $phones)));

        if ($key === null || ! isset($this->rows[$key])) {
            foreach ($emails as $email) {
                if (isset($this->byEmail[mb_strtolower($email)])) {
                    $key = $this->byEmail[mb_strtolower($email)];
                    break;
                }
            }
        }

        $key ??= 'e:'.(mb_strtolower($emails[0] ?? '') ?: Str::uuid());
        $this->rows[$key] ??= ['name' => trim($name), 'capacity' => [], 'for' => [], 'source' => [], 'phones' => [], 'emails' => [], 'theirs' => []];

        $row = &$this->rows[$key];
        $row['name'] = $row['name'] !== '' ? $row['name'] : trim($name);
        $row['capacity'] = array_values(array_filter([...$row['capacity'], trim($capacity)]));
        $row['for'] = array_values(array_filter([...$row['for'], trim((string) $for)]));
        $row['source'][] = $source;
        $row['phones'] = [...$row['phones'], ...$phones];
        $row['emails'] = [...$row['emails'], ...$emails];

        foreach ($emails as $email) {
            $this->byEmail[mb_strtolower($email)] ??= $key;
        }

        return $key;
    }
}
