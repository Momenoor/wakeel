<?php

namespace Tests\Feature;

use App\Filament\Mms\Resources\Parties\Pages\CreateParty;
use App\Filament\Mms\Resources\Parties\Pages\EditParty;
use App\Filament\Mms\Resources\Parties\Schemas\PartyForm;
use App\Models\Party;
use App\Models\User;
use App\Support\PartyNames;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * A party is not entered twice: its name kept tidy and compared however typed (spaces, hamza, "ذ.م.م") or a
 * letter or two off; a likely duplicate refused on the form unless said to be another; the duplicates there are
 * found for merging.
 */
class PartyDuplicatesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Gate::before(fn () => true);
        Filament::setCurrentPanel(Filament::getPanel('mms'));
        Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        $this->actingAs(User::factory()->create()->assignRole('super-admin'));
    }

    public function test_names_however_typed_have_one_key_and_a_misspelling_is_close(): void
    {
        $this->assertSame('الدار العقاريه', PartyNames::key("\tالدار  العقارية - ش.م.ع "));
        $this->assertSame(PartyNames::key('الدار العقارية ش م ع'), PartyNames::key('الدار العقاريه'));
        $this->assertSame(PartyNames::key('أحمد عبد الله'), PartyNames::key('احمد عبدالله'));
        $this->assertSame(PartyNames::key('Emirates Steel LLC'), PartyNames::key('emirates  steel'));
        $this->assertSame(PartyNames::key('مُحَمَّد'), PartyNames::key('محمد'));

        $this->assertTrue(PartyNames::close(PartyNames::key('شركة النور للتجارة العامة'), PartyNames::key('شركة النور للتجاره العامه')));
        $this->assertTrue(PartyNames::close(PartyNames::key('شركة النور للتجارة'), PartyNames::key('شركة النورر للتجارة')));
        $this->assertFalse(PartyNames::close(PartyNames::key('علي'), PartyNames::key('عمر')));
        $this->assertFalse(PartyNames::close(PartyNames::key('شركة النور للتجارة'), PartyNames::key('شركة الأمل للمقاولات')));
    }

    public function test_a_name_is_kept_tidy_with_its_key_and_its_likely_duplicates_found(): void
    {
        $party = Party::factory()->create(['name' => "  شركة   النور للتجارة\t", 'email' => [], 'phone' => []]);
        $this->assertSame('شركة النور للتجارة', $party->name);
        $this->assertSame('شركه النور للتجاره', $party->name_key);

        $this->assertSame([$party->id], Party::likelyDuplicates('شركة النور للتجاره ذ.م.م')->pluck('id')->all());
        $this->assertSame([$party->id], Party::likelyDuplicates('شركة النورر للتجارة')->pluck('id')->all());
        $this->assertSame([], Party::likelyDuplicates('شركة النور للتجارة', $party->id)->all());
        // Under an entity, another level: not the same.
        $this->assertSame([], Party::likelyDuplicates('شركة النور للتجارة', parentId: 999)->all());
        $this->assertSame($party->id, PartyForm::existingMatch(' شركة النور للتجاره ')?->id);
    }

    public function test_the_form_refuses_a_likely_duplicate_unless_said_to_be_another(): void
    {
        $there = Party::factory()->create(['name' => 'محمد أحمد علي', 'email' => [], 'phone' => []]);

        Livewire::test(CreateParty::class)
            ->fillForm(['name' => 'محمد احمد  علي', 'role' => ['role' => ['party']]])
            ->assertSee('#'.$there->id)
            ->call('create')
            ->assertHasFormErrors(['name']);
        $this->assertSame(1, Party::query()->count());

        Livewire::test(CreateParty::class)
            ->fillForm(['name' => 'محمد احمد  علي', 'role' => ['role' => ['party']], 'not_a_duplicate' => true])
            ->call('create')
            ->assertHasNoFormErrors();
        $this->assertSame(2, Party::query()->count());

        // Editing it, its own name is not a duplicate of itself.
        Livewire::test(EditParty::class, ['record' => $there->getRouteKey()])
            ->fillForm(['name' => 'محمد أحمد علي'])
            ->call('save')
            ->assertHasNoFormErrors(['name']);
    }

    public function test_the_duplicates_there_are_listed_for_review_and_merged_from_the_list(): void
    {
        $a = Party::factory()->create(['name' => 'الدار العقارية ش.م.ع', 'email' => ['info@aldar.test'], 'phone' => []]);
        $b = Party::factory()->create(['name' => 'الدار العقارية - ش م ع', 'email' => [], 'phone' => []]);
        Party::factory()->create(['name' => 'علي', 'email' => [], 'phone' => []]);
        Party::factory()->create(['name' => 'علي', 'email' => [], 'phone' => []]);
        Party::factory()->create(['name' => 'شركة أخرى', 'email' => [], 'phone' => []]);

        $csv = tempnam(sys_get_temp_dir(), 'dup');
        $this->artisan('parties:duplicates', ['file' => $csv])->expectsOutputToContain('2 groups of the same party')->assertSuccessful();
        $rows = array_map(fn (string $line): array => str_getcsv($line, escape: ''), array_filter(explode("\n", (string) preg_replace('/^\x{FEFF}/u', '', (string) file_get_contents($csv)))));

        $this->assertSame(['group', 'action', 'id'], array_slice($rows[0], 0, 3));
        // The one-word name: not merged unless said so.
        $this->assertSame(['keep', 'skip'], array_column(array_values(array_filter($rows, fn ($r) => $r[3] === 'علي')), 1));
        $this->assertSame([['keep', (string) $a->id], ['merge', (string) $b->id]], array_map(fn ($r) => [$r[1], $r[2]], array_values(array_filter($rows, fn ($r) => str_starts_with($r[3], 'الدار')))));

        $this->artisan('parties:merge-plan', ['file' => $csv, '--apply' => true])->assertSuccessful();
        @unlink($csv);

        $this->assertNull(Party::query()->find($b->id));
        $this->assertSame(4, Party::query()->count());
    }
}
