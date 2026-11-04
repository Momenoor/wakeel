<?php

namespace App\Services\MMS;

use App\Models\Matter;
use App\Models\MatterContact;
use App\Models\MatterCreditor;
use App\Models\MatterParty;
use App\Models\Party;
use App\Support\Addresses;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Someone the matter's emails brought in (a row of its Contacts tab that is no party and no creditor) found to be
 * someone it knows: merged into them — their addresses (and phones) a party's, a creditor's or a creditor's agent's —
 * or made their agent — a party's representative on the matter, or a creditor's agent. A new creditor can be named for
 * either. Their emails that came in then move to the folder of what they now are (MatterCreditors::refile()): a
 * creditor's, a side's, or the general one.
 */
final class ContactLinker
{
    public const MERGE = 'merge';

    public const AGENT = 'agent';

    /** The target: a creditor named in the form. */
    public const NEW_CREDITOR = 'new';

    public function __construct(private readonly MatterCreditors $creditors) {}

    /**
     * Whom the rows can be merged into or made agents of — the matter's parties, creditors and (to merge into) their
     * agents — key => name, by kind: as MatterContactsExport::rows() gives them.
     *
     * @param  array<string, array<string, mixed>>  $rows  by key
     * @return array<string, array<string, string>>
     */
    public static function targets(array $rows, string $how, bool $creditors): array
    {
        $targets = [];

        foreach ($rows as $key => $row) {
            $label = trim($row['name'].($row['capacity'] !== '' ? ' — '.$row['capacity'] : ''));

            if ($row['party_id'] !== null) {
                $targets[__('Parties')][$key] = $label;
            } elseif ($row['creditor_id'] !== null && ! $row['agent']) {
                $targets[__('Creditors')][$key] = $row['name'];
            } elseif ($row['agent'] && $how === self::MERGE) {
                $targets[__("Creditors' agents")][$key] = $row['name'].' — '.__('On behalf of').': '.$row['for'];
            }
        }

        if ($creditors) {
            $targets[__('Creditors')] = [self::NEW_CREDITOR => __('A new creditor'), ...($targets[__('Creditors')] ?? [])];
        }

        return $targets;
    }

    /** A row that can be linked: someone who came by email — no party, no creditor — with an address. */
    public static function linkable(array $row): bool
    {
        return $row['party_id'] === null && $row['creditor_id'] === null && $row['own'] !== [];
    }

    /**
     * The rows (those linkable()) merged into the target, or made its agents. How many were, and how many of their
     * emails moved.
     *
     * @param  iterable<array<string, mixed>>  $rows
     * @param  ?array<string, mixed>  $target  its row; null with $creditorName: a new creditor
     * @return array{linked: int, moved: int}
     */
    public function link(Matter $matter, iterable $rows, string $how, ?array $target, ?string $creditorName = null): array
    {
        $rows = collect($rows)->filter(fn (array $row): bool => self::linkable($row))->values();

        if ($rows->isEmpty()) {
            return ['linked' => 0, 'moved' => 0];
        }

        $addresses = $rows->flatMap(fn (array $row): array => array_map('mb_strtolower', $row['own']))->unique()->values()->all();

        DB::transaction(function () use ($matter, $rows, $how, $target, $creditorName): void {
            if ($target === null) {
                $name = trim((string) $creditorName) ?: (string) $rows->first()['name'];
                $target = ['party_id' => null, 'agent' => false, 'name' => $name, 'creditor_id' => (int) $matter->creditors()->create(['name' => $name])->getKey()];
            }

            match (true) {
                $target['party_id'] !== null && $how === self::MERGE => $this->intoParty($rows->all(), (int) $target['party_id']),
                $target['party_id'] !== null => $this->representatives($matter, $rows->all(), (int) $target['party_id']),
                $target['creditor_id'] !== null => $this->toCreditor($matter, $rows->all(), $how, $target),
                default => throw new RuntimeException(__('Choose whom they are.')),
            };
        });

        return ['linked' => $rows->count(), 'moved' => $this->creditors->refile($matter, $addresses)];
    }

    /**
     * Their addresses and phones the party's own (on the party: wherever it appears).
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function intoParty(array $rows, int $partyId): void
    {
        $party = Party::query()->findOrFail($partyId);
        $emails = Party::values($party->email);
        $phones = Party::values($party->phone);

        foreach ($rows as $row) {
            $emails = Addresses::emails([...$emails, ...$row['own']]);
            $phones = self::phones($phones, $row);
        }

        $party->update(['email' => $emails, 'phone' => $phones]);
    }

    /**
     * Each one the party's representative on the matter: a party of their own (the one with their address, else of
     * their name, else a new one), under the party's place on it.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function representatives(Matter $matter, array $rows, int $partyId): void
    {
        $places = $matter->matterParties()->where('party_id', $partyId)->get();
        $under = $places->firstWhere('role', 'party') ?? $places->first() ?? throw new RuntimeException(__('The party is not on this matter.'));

        foreach ($rows as $row) {
            $id = collect($row['own'])->map(fn (string $email) => MatterContact::partiesWith($email)->first())->filter()->first()
                ?? Party::query()->where('name', $row['name'])->oldest('id')->value('id');
            $party = $id ? Party::query()->findOrFail($id) : Party::create(['name' => $row['name'], 'role' => ['role' => ['representative']]]);
            $party->update([
                'email' => Addresses::emails([...Party::values($party->email), ...$row['own']]),
                'phone' => self::phones(Party::values($party->phone), $row),
            ]);

            MatterParty::query()->firstOrCreate(
                ['matter_id' => $matter->getKey(), 'party_id' => $party->getKey(), 'role' => 'representative', 'parent_id' => $under->getKey()],
                ['type' => $under->type],
            );
        }
    }

    /**
     * Their addresses the creditor's own, or — made its agents, or merged into one of its agents — its agents', each
     * agent by their name.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $target
     */
    private function toCreditor(Matter $matter, array $rows, string $how, array $target): void
    {
        $creditorId = (int) $target['creditor_id'];
        $agent = $how === self::AGENT || $target['agent'];
        $phones = [];

        foreach ($rows as $row) {
            foreach ($row['own'] as $email) {
                MatterCreditor::assign($matter, $creditorId, null, $email, $agent, $agent ? ($how === self::AGENT ? $row['name'] : $target['name']) : null);
            }
            $phones = self::phones($phones, $row);
        }

        // Merged into the creditor itself: their phones its too.
        if (! $agent && $phones !== []) {
            $creditor = $matter->creditors()->findOrFail($creditorId);
            $creditor->update(['phones' => self::phones((array) $creditor->phones, ['phones' => implode(',', $phones)])]);
        }
    }

    /**
     * The phones with a row's added — the same number once, however written.
     *
     * @param  list<string>  $phones
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    private static function phones(array $phones, array $row): array
    {
        return collect([...$phones, ...explode(',', (string) ($row['phones'] ?? ''))])
            ->map(fn ($phone): string => trim((string) $phone))
            ->filter()
            ->unique(fn (string $phone): string => Addresses::phoneKey($phone) ?: $phone)
            ->values()
            ->all();
    }
}
