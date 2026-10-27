<?php

namespace Tests\Feature;

use App\Filament\Mms\Resources\Matters\Pages\EditMatter;
use App\Filament\Mms\Resources\Parties\Schemas\PartyForm;
use App\Models\Matter;
use App\Models\Party;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The matter form: a party added there that is already in the system — by its name, a phone or an email — is that
 * party, chosen, not another of the same; and a matter's year, of the last five years for a new one, kept as it is
 * on one being edited.
 */
class MatterFormPartyPickingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Gate::before(fn () => true);
        Filament::setCurrentPanel('mms');
        $this->actingAs(User::factory()->create());
    }

    public function test_a_party_already_in_the_system_is_used_with_the_new_role_and_contacts(): void
    {
        $salem = Party::factory()->create(['name' => 'Salem Office', 'role' => ['role' => ['party']], 'phone' => ['0501112233'], 'email' => ['salem@office.ae']]);

        // By its name, any case: the same party, a representative now too, its new phone kept.
        $id = PartyForm::createOrUse(['name' => 'salem office', 'role' => ['role' => ['representative']], 'phone' => ['0509998877'], 'email' => []]);
        $salem->refresh();
        $this->assertSame($salem->id, $id);
        $this->assertSame(['party', 'representative'], $salem->role['role']);
        $this->assertSame(['0501112233', '0509998877'], $salem->phone);

        // By a phone, written another way: the same.
        $this->assertSame($salem->id, PartyForm::createOrUse(['name' => 'S. Office LLC', 'role' => ['role' => ['party']], 'phone' => ['+971 50 111 2233']]));
        // By an email.
        $this->assertSame($salem->id, PartyForm::createOrUse(['name' => 'Another Name', 'role' => ['role' => ['party']], 'email' => ['SALEM@office.ae']]));
        $this->assertSame(1, Party::where('name', 'like', '%Office%')->count());

        // Nothing of it in the system: a new party.
        $new = PartyForm::createOrUse(['name' => 'New Party', 'role' => ['role' => ['party']], 'phone' => ['0507776655']]);
        $this->assertNotSame($salem->id, $new);
        $this->assertSame('New Party', Party::find($new)->name);
    }

    public function test_a_marketer_added_on_the_form_is_the_party_already_in_the_system(): void
    {
        $matter = Matter::factory()->create();
        $salem = Party::factory()->create(['name' => 'Salem Office', 'role' => ['role' => ['party']], 'phone' => ['0501112233']]);

        // Added from the marketer's list by a phone already on Salem: Salem, a marketer now, chosen in the row.
        Livewire::test(EditMatter::class, ['record' => $matter->getRouteKey()])
            ->set('data.marketers', ['row' => ['party_id' => null, 'marketing_part' => null]])
            ->callAction(TestAction::make('createOption')->schemaComponent('marketers.row.party_id'), ['name' => 'Riyada Marketing', 'phone' => ['0501112233'], 'email' => []])
            ->assertHasNoActionErrors()
            ->assertSet('data.marketers.row.party_id', $salem->id);

        $this->assertContains('marketer', $salem->fresh()->role['role']);
        $this->assertSame(0, Party::where('name', 'Riyada Marketing')->count());
    }

    public function test_a_matter_edited_keeps_its_year_however_old(): void
    {
        // (A new one is held to the last five years: its form's expert list asks MySQL's JSON of the database,
        // which SQLite cannot answer, so it is not opened here.)
        $old = Matter::factory()->create(['year' => (string) now()->subYears(8)->year]);
        $old->update(['level' => 'first_instance', 'next_session_date' => now()->addWeek()]);

        Livewire::test(EditMatter::class, ['record' => $old->getRouteKey()])
            ->call('save')
            ->assertHasNoFormErrors();
    }
}
