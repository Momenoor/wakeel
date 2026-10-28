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
 * The matter form: a party added there that is already in the system by its name is that party, chosen, not
 * another of the same — by its name only: a phone or email may be another's too (a party's lawyer's), so a new
 * name with one is a new party, keeping them; and a matter's year, of the last five years for a new one, kept as
 * it is on one being edited.
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

    public function test_a_party_already_in_the_system_by_its_name_is_used_with_the_new_role_and_contacts(): void
    {
        $salem = Party::factory()->create(['name' => 'Salem Office', 'role' => ['role' => ['party']], 'phone' => ['0501112233'], 'email' => ['salem@office.ae']]);

        // By its name, any case: the same party, a representative now too, its new phone kept.
        $id = PartyForm::createOrUse(['name' => 'salem office', 'role' => ['role' => ['representative']], 'phone' => ['0509998877'], 'email' => []]);
        $salem->refresh();
        $this->assertSame($salem->id, $id);
        $this->assertSame(['party', 'representative'], $salem->role['role']);
        $this->assertSame(['0501112233', '0509998877'], $salem->phone);
    }

    public function test_another_name_with_a_phone_or_email_already_in_the_system_is_a_new_party_with_them(): void
    {
        // A law office, whose phone and email a client was given with.
        $office = Party::factory()->create(['name' => 'Salem Law Office', 'role' => ['role' => ['representative']], 'phone' => ['0501112233'], 'email' => ['info@salem.law']]);

        $id = PartyForm::createOrUse(['name' => 'Stamina Academy', 'role' => ['role' => ['party']], 'phone' => ['0501112233'], 'email' => ['info@salem.law']]);

        $this->assertNotSame($office->id, $id);
        $client = Party::find($id);
        $this->assertSame('Stamina Academy', $client->name);
        $this->assertSame(['0501112233'], $client->phone);
        $this->assertSame(['info@salem.law'], $client->email);
        // The office is as it was.
        $this->assertSame(['representative'], $office->fresh()->role['role']);
    }

    public function test_a_marketer_added_on_the_form_by_a_name_already_in_the_system_is_that_party(): void
    {
        $matter = Matter::factory()->create();
        $salem = Party::factory()->create(['name' => 'Salem Office', 'role' => ['role' => ['party']], 'phone' => ['0501112233']]);

        // Added from the marketer's list by Salem's name: Salem, a marketer now, chosen in the row.
        Livewire::test(EditMatter::class, ['record' => $matter->getRouteKey()])
            ->set('data.marketers', ['row' => ['party_id' => null, 'marketing_part' => null]])
            ->callAction(TestAction::make('createOption')->schemaComponent('marketers.row.party_id'), ['name' => 'Salem Office', 'phone' => ['0501112233'], 'email' => []])
            ->assertHasNoActionErrors()
            ->assertSet('data.marketers.row.party_id', $salem->id);

        $this->assertContains('marketer', $salem->fresh()->role['role']);
        $this->assertSame(1, Party::where('name', 'Salem Office')->count());
    }

    public function test_a_party_chosen_without_the_lists_role_is_shown_by_its_name_not_its_id(): void
    {
        $matter = Matter::factory()->create();
        // A party, not (yet) a marketer: the marketers' list looks among marketers and employees only.
        $salem = Party::factory()->create(['name' => 'Salem Office', 'role' => ['role' => ['party']]]);

        Livewire::test(EditMatter::class, ['record' => $matter->getRouteKey()])
            ->set('data.marketers', ['row' => ['party_id' => $salem->id, 'marketing_part' => null]])
            ->assertSee('Salem Office');
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
