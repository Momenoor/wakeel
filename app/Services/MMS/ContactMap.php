<?php

namespace App\Services\MMS;

use App\Models\Party;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The shared contact map (Contact_Map.pdf: courts and judicial departments, banks, government bodies and companies)
 * as parties: each body an external entity, the people and offices on its cards its contacts — parties of their own
 * under it (parent_id), external entities too, each with its position there (title). Picked as a letter's
 * recipient or copied in on its email (LettersRelationManager, EmailSendFields::ccEntity()).
 *
 * The list itself is not in the code (people's phones and emails): a JSON file of its cards — each
 * {entity, name?, title?, kind?, city?, phones[], emails[]}, a card with no name the entity's own — uploaded on the
 * Parties page ("Import contact map") or given to `php artisan parties:import-contact-map {path}`.
 *
 * Run again it adds nothing twice: an entity is found by its name (a party there already of that name, of whatever
 * role, is made an external entity too), a contact by its name under its entity; their phones and emails take any
 * new ones.
 */
class ContactMap
{
    /**
     * @return array{entities: int, contacts: int} how many were made new
     */
    public function importFile(string $path): array
    {
        $records = json_decode((string) @file_get_contents($path), true);

        if (! is_array($records) || ! array_is_list($records)) {
            throw new RuntimeException(__('That file is not a contact map: a JSON list of cards was expected.'));
        }

        return $this->import($records);
    }

    /**
     * @param  list<array<string, mixed>>  $records
     * @return array{entities: int, contacts: int} how many were made new
     */
    public function import(array $records): array
    {
        $made = ['entities' => 0, 'contacts' => 0];

        // Quietly: no activity row and no English name asked of Claude for each of hundreds ("Translate names
        // now" does them when wanted).
        DB::transaction(function () use ($records, &$made): void {
            Party::withoutEvents(function () use ($records, &$made): void {
                $entities = [];

                foreach ($records as $record) {
                    $name = trim((string) ($record['entity'] ?? ''));

                    if ($name === '') {
                        continue;
                    }

                    $entity = $entities[$name] ??= $this->entity($name, $made);
                    $this->fill($entity, $record['kind'] ?? null, $record['city'] ?? null, $record['title'] ?? null);

                    $contact = trim((string) ($record['name'] ?? ''));
                    $target = $contact === '' ? $entity : $this->contact($entity, $contact, $made);

                    if ($target !== $entity) {
                        $this->fill($target, $record['kind'] ?? null, $record['city'] ?? null, $record['title'] ?? null);
                    }

                    $this->addDetails($target, (array) ($record['phones'] ?? []), (array) ($record['emails'] ?? []));
                }
            });
        });

        return $made;
    }

    /** The entity of that name: the party there already (made an external entity), else a new one. */
    private function entity(string $name, array &$made): Party
    {
        $party = Party::query()->whereNull('parent_id')->where('name', $name)->orderBy('id')->first();

        if (! $party) {
            $made['entities']++;

            return $this->create($name, null);
        }

        $this->makeExternal($party);

        return $party;
    }

    private function contact(Party $entity, string $name, array &$made): Party
    {
        $party = Party::query()->where('parent_id', $entity->getKey())->where('name', $name)->orderBy('id')->first();

        if ($party) {
            $this->makeExternal($party);

            return $party;
        }

        $made['contacts']++;

        return $this->create($name, $entity->getKey());
    }

    private function create(string $name, ?int $parentId): Party
    {
        $party = new Party(['name' => $name, 'parent_id' => $parentId]);
        $party->role = [['role' => 'external']];
        $party->phone = [];
        $party->email = [];
        $party->save();

        return $party;
    }

    /** Its roles as stored, with "external" among them. */
    private function makeExternal(Party $party): void
    {
        $roles = json_decode((string) $party->getRawOriginal('role'), true);
        $roles = is_array($roles) && ! isset($roles['role']) ? $roles : [];

        if (collect($roles)->contains(fn ($r): bool => ($r['role'] ?? null) === 'external')) {
            return;
        }

        $party->role = [...$roles, ['role' => 'external']];
        $party->save();
    }

    /** What it is, where, and its title there: each only where it has none yet. */
    private function fill(Party $party, ?string $kind, ?string $city, ?string $title): void
    {
        $party->entity_kind = $party->parent_id === null ? ($party->entity_kind ?: $kind) : $party->entity_kind;
        $party->address = filled($party->address) ? $party->address : ($city ?: null);

        if ($party->parent_id !== null && filled($title) && blank($party->title)) {
            $party->title = $title;
        }

        if ($party->isDirty()) {
            $party->save();
        }
    }

    /**
     * @param  list<string>  $phones
     * @param  list<string>  $emails
     */
    private function addDetails(Party $party, array $phones, array $emails): void
    {
        $merge = fn (array $have, array $new, bool $caseless): array => collect([...$have, ...$new])
            ->map(fn ($v): string => trim((string) $v))
            ->filter()
            ->unique(fn (string $v): string => $caseless ? mb_strtolower($v) : $v)
            ->values()->all();

        $party->phone = $merge(array_values(array_filter((array) $party->phone)), $phones, false);
        $party->email = $merge(array_values(array_filter((array) $party->email)), $emails, true);

        if ($party->isDirty()) {
            $party->save();
        }
    }
}
