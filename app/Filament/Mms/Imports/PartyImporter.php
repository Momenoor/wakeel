<?php

namespace App\Filament\Mms\Imports;

use App\Models\Party;
use Filament\Actions\Imports\Exceptions\RowImportFailedException;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Support\Number;

/**
 * Parties from a spreadsheet, one a row, matched by name: one already there
 * is updated (a corrected sheet can be uploaded again), any other made.
 * Updated: its roles, expert types, parent and legal name (and address,
 * fax) become the sheet's where the sheet gives them — an empty cell leaves
 * them as they were; its phones and emails are only ever added to.
 *
 * A cell may hold several phones or emails, and several roles, split by
 * ";" or "," (or "؛" / "،") — added to what the party has, each once. Roles
 * and expert types by key or by name, in Arabic or English ("طرف؛ ممثل",
 * "external"). The parent ("Belongs to") by name: made when no party has it.
 */
class PartyImporter extends Importer
{
    protected static ?string $model = Party::class;

    /** What a cell is split on. */
    private const SPLIT = '/\s*[;,؛،]\s*/u';

    public static function getColumns(): array
    {
        $columns = [
            ImportColumn::make('name')
                ->label(__('Name'))
                ->requiredMapping()
                ->rules(['required', 'max:191'])
                ->examples(['شركة المهاد للتجارة', 'سالم أحمد', 'القيادة العامة لشرطة دبي']),

            ImportColumn::make('legal_name')
                ->label(__('Legal name'))
                // Empty: what the party has stays.
                ->fillRecordUsing(fn (Party $record, ?string $state) => filled($state) ? $record->legal_name = trim($state) : null)
                ->rules(['nullable', 'max:255'])
                ->examples(['شركة المهاد للتجارة ذ.م.م', '', '']),

            ImportColumn::make('roles')
                ->label(__('Role'))
                ->fillRecordUsing(fn () => null)
                ->examples(['party', 'employee; attendee', 'جهة خارجية']),

            ImportColumn::make('expert_types')
                ->label(__('Expert Type'))
                ->fillRecordUsing(fn () => null)
                ->examples(['', '', '']),

            ImportColumn::make('phones')
                ->label(__('Phone'))
                ->fillRecordUsing(fn () => null)
                ->examples(['042223333; 0501112222', '0559998888', '043111111']),

            ImportColumn::make('emails')
                ->label(__('Email'))
                ->fillRecordUsing(fn () => null)
                ->examples(['info@almahad.ae; accounts@almahad.ae', 'salem@almahad.ae', 'info@dubaipolice.gov.ae; legal@dubaipolice.gov.ae']),

            ImportColumn::make('parent')
                ->label(__('Belongs to'))
                ->fillRecordUsing(fn () => null)
                ->examples(['', 'شركة المهاد للتجارة', '']),

            ImportColumn::make('address')
                ->label(__('Address'))
                // Empty: what the party has stays.
                ->fillRecordUsing(fn (Party $record, ?string $state) => filled($state) ? $record->address = trim($state) : null)
                ->examples(['دبي — الخليج التجاري', '', 'دبي — القرهود']),

            ImportColumn::make('fax')
                ->label(__('Fax'))
                // Empty: what the party has stays.
                ->fillRecordUsing(fn (Party $record, ?string $state) => filled($state) ? $record->fax = trim($state) : null)
                ->examples(['', '', '']),
        ];

        // "Download example" heads each column in the panel's language.
        foreach ($columns as $column) {
            $column->exampleHeader(fn (ImportColumn $col): string => $col->getLabel() ?? $col->getName());
        }

        return $columns;
    }

    /** One of that name: updated; else a new one. */
    public function resolveRecord(): Party
    {
        return Party::query()->where('name', trim((string) ($this->data['name'] ?? '')))->first() ?? new Party;
    }

    protected function afterFill(): void
    {
        /** @var Party $party */
        $party = $this->record;
        $party->name = trim((string) $party->name);

        // Roles and expert types: as the sheet says them, when it does —
        // else as they were. A new party given none is a party.
        $current = $party->exists ? (array) ($party->role ?? []) : [];
        $roles = array_values(array_unique(self::keys($this->data['roles'] ?? null, Party::roleOptions(...), 'Role'))) ?: (array) ($current['role'] ?? []);
        $types = array_values(array_unique(self::keys($this->data['expert_types'] ?? null, Party::expertTypeOptions(...), 'Expert Type'))) ?: (array) ($current['type'] ?? []);

        if ($types !== [] && ! in_array('expert', $roles, true)) {
            $roles[] = 'expert';
        }

        $party->role = ['role' => $roles ?: ['party'], 'type' => $types, 'field' => $current['field'] ?? null];

        // Phones and emails: added to its own — none taken away — each once, the sheet's last.
        $party->phone = self::merged((array) Party::values($party->phone), self::split($this->data['phones'] ?? null), fn (string $p): string => preg_replace('/\D+/', '', $p) ?? $p);
        $emails = self::split($this->data['emails'] ?? null);
        foreach ($emails as $email) {
            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RowImportFailedException(__('":email" is not an email address.', ['email' => $email]));
            }
        }
        $party->email = self::merged((array) Party::values($party->email), $emails, fn (string $e): string => mb_strtolower($e));

        // Belongs to: the party of that name — made when none has it; never itself. Empty: as it was.
        $parentName = trim((string) ($this->data['parent'] ?? ''));
        if ($parentName !== '') {
            if ($parentName === $party->name) {
                throw new RowImportFailedException(__('A party cannot belong to itself.'));
            }

            $party->parent_id = (Party::query()->where('name', $parentName)->first()
                ?? Party::create(['name' => $parentName, 'role' => ['role' => ['party']]]))->getKey();
        }
    }

    /**
     * A cell's values.
     *
     * @return list<string>
     */
    private static function split(mixed $cell): array
    {
        return array_values(array_filter(array_map('trim', preg_split(self::SPLIT, trim((string) $cell)) ?: []), fn (string $v) => $v !== ''));
    }

    /**
     * What's there, then the new — each once (as the key says).
     *
     * @param  list<string>  $have
     * @param  list<string>  $new
     * @param  callable(string): string  $key
     * @return list<string>
     */
    private static function merged(array $have, array $new, callable $key): array
    {
        $out = [];
        foreach ([...$have, ...$new] as $value) {
            $out[$key((string) $value)] = (string) $value;
        }

        return array_values($out);
    }

    /**
     * A cell's roles (or expert types) as keys — given as keys, or by name
     * in Arabic or English.
     *
     * @param  callable(): array<string, string>  $options  key => name, in the language now
     * @return list<string>
     */
    private static function keys(mixed $cell, callable $options, string $what): array
    {
        $byName = [];
        foreach (['ar', 'en'] as $locale) {
            foreach (self::inLocale($locale, $options) as $key => $name) {
                $byName[mb_strtolower((string) $key)] = $key;
                $byName[mb_strtolower(trim((string) $name))] = $key;
            }
        }

        return array_map(fn (string $value): string => $byName[mb_strtolower($value)]
            ?? throw new RowImportFailedException(__(':what ":value" is not known.', ['what' => __($what), 'value' => $value])),
            self::split($cell));
    }

    /**
     * @param  callable(): array<string, string>  $options
     * @return array<string, string>
     */
    private static function inLocale(string $locale, callable $options): array
    {
        $now = app()->getLocale();
        app()->setLocale($locale);

        try {
            return $options();
        } finally {
            app()->setLocale($now);
        }
    }

    public static function getCompletedNotificationBody(Import $import): string
    {
        $body = __(':count party(ies) imported.', ['count' => Number::format($import->successful_rows)]);

        if ($failedRowsCount = $import->getFailedRowsCount()) {
            $body .= ' '.__(':count row(s) failed to import.', ['count' => Number::format($failedRowsCount)]);
        }

        return $body;
    }
}
