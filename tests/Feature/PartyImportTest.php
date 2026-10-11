<?php

namespace Tests\Feature;

use App\Filament\Mms\Imports\PartyImporter;
use App\Models\Party;
use App\Models\User;
use Filament\Actions\Imports\Exceptions\RowImportFailedException;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Parties from a spreadsheet: several phones and emails a cell, roles by
 * name, the party each belongs to — matched by name, so a sheet can be
 * imported again.
 */
class PartyImportTest extends TestCase
{
    use RefreshDatabase;

    private const COLUMNS = ['name' => 'Name', 'legal_name' => 'Legal name', 'roles' => 'Role', 'expert_types' => 'Expert Type',
        'phones' => 'Phone', 'emails' => 'Email', 'parent' => 'Belongs to', 'address' => 'Address', 'fax' => 'Fax'];

    /** One row through the importer, as the Import button runs it. */
    private function import(array $row): void
    {
        $import = Import::create(['file_name' => 'parties.csv', 'file_path' => 'parties.csv', 'importer' => PartyImporter::class,
            'total_rows' => 1, 'user_id' => User::factory()->create()->id]);

        (new PartyImporter($import, self::COLUMNS, []))([...array_fill_keys(array_values(self::COLUMNS), ''), ...$row]);
    }

    public function test_the_example_rows_import_with_their_phones_emails_roles_and_parent(): void
    {
        // As the "Download example" file has them.
        $examples = collect(PartyImporter::getColumns())->mapWithKeys(fn ($column) => [self::COLUMNS[$column->getName()] => $column->getExamples()]);
        foreach (range(0, 2) as $i) {
            $this->import($examples->map(fn (array $values) => $values[$i] ?? '')->all());
        }

        $company = Party::where('name', 'شركة المهاد للتجارة')->sole();
        $this->assertSame(['042223333', '0501112222'], $company->phone);
        $this->assertSame(['info@almahad.ae', 'accounts@almahad.ae'], $company->email);
        $this->assertSame('شركة المهاد للتجارة ذ.م.م', $company->legal_name);

        $employee = Party::where('name', 'سالم أحمد')->sole();
        $this->assertSame($company->id, $employee->parent_id);
        $this->assertEqualsCanonicalizing(['employee', 'attendee'], $employee->role['role']);

        // A role by its Arabic name.
        $police = Party::where('name', 'القيادة العامة لشرطة دبي')->sole();
        $this->assertTrue(Party::query()->withRole('external')->whereKey($police->id)->exists());
        $this->assertSame(['info@dubaipolice.gov.ae', 'legal@dubaipolice.gov.ae'], $police->email);
    }

    public function test_a_party_already_there_is_added_to_not_duplicated(): void
    {
        $party = Party::factory()->create(['name' => 'مكتب البنا', 'phone' => ['0501112222'], 'email' => ['old@albanna.ae'], 'role' => ['role' => ['representative']]]);

        $this->import(['Name' => 'مكتب البنا', 'Role' => 'Party', 'Phone' => '050-111-2222، 042220000', 'Email' => 'OLD@albanna.ae; new@albanna.ae']);

        $party->refresh();
        $this->assertSame(1, Party::where('name', 'مكتب البنا')->count());
        // The same number written another way, the same email in capitals: once.
        $this->assertSame(['050-111-2222', '042220000'], $party->phone);
        $this->assertSame(['OLD@albanna.ae', 'new@albanna.ae'], $party->email);
        // The role as the sheet says it.
        $this->assertSame(['party'], $party->role['role']);
    }

    public function test_a_party_already_there_takes_the_sheets_changes_and_keeps_the_rest(): void
    {
        $old = Party::factory()->create(['name' => 'Old firm']);
        $new = Party::factory()->create(['name' => 'New firm']);
        $party = Party::factory()->create(['name' => 'سالم', 'legal_name' => 'سالم أحمد', 'address' => 'دبي', 'parent_id' => $old->id,
            'phone' => ['0501112222'], 'email' => ['salem@old.ae'], 'role' => ['role' => ['expert'], 'type' => ['certified']]]);

        // Changed: role, expert type, parent, legal name. Empty: address stays. Contacts: added.
        $this->import(['Name' => 'سالم', 'Role' => 'expert', 'Expert Type' => 'Assistant Expert', 'Belongs to' => 'New firm',
            'Legal name' => 'سالم أحمد محمد', 'Phone' => '0559998888', 'Email' => 'salem@new.ae']);

        $party->refresh();
        $this->assertSame(['expert'], $party->role['role']);
        $this->assertSame(['assistant'], $party->role['type']);
        $this->assertSame($new->id, $party->parent_id);
        $this->assertSame('سالم أحمد محمد', $party->legal_name);
        $this->assertSame('دبي', $party->address);
        $this->assertSame(['0501112222', '0559998888'], $party->phone);
        $this->assertSame(['salem@old.ae', 'salem@new.ae'], $party->email);

        // Nothing but the name: nothing changes.
        $this->import(['Name' => 'سالم']);
        $party->refresh();
        $this->assertSame([['expert'], ['assistant'], $new->id, 'سالم أحمد محمد'], [$party->role['role'], $party->role['type'], $party->parent_id, $party->legal_name]);
    }

    public function test_a_parent_not_there_is_made_and_an_expert_type_makes_an_expert(): void
    {
        $this->import(['Name' => 'خالد', 'Expert Type' => 'assistant', 'Belongs to' => 'مكتب جديد']);

        $khaled = Party::where('name', 'خالد')->sole();
        $this->assertSame('مكتب جديد', $khaled->parent->name);
        $this->assertTrue(Party::query()->withRole('expert', 'assistant')->whereKey($khaled->id)->exists());
    }

    public function test_a_bad_row_says_why(): void
    {
        foreach ([
            ['Name' => 'أ', 'Role' => 'مدير'],
            ['Name' => 'ب', 'Email' => 'not-an-email'],
            ['Name' => 'ج', 'Belongs to' => 'ج'],
        ] as $row) {
            try {
                $this->import($row);
                $this->fail('Imported: '.json_encode($row, JSON_UNESCAPED_UNICODE));
            } catch (RowImportFailedException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }

        $this->assertSame(0, Party::whereIn('name', ['أ', 'ب', 'ج'])->count());
    }
}
