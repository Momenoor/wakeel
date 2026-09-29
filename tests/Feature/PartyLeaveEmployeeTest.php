<?php

namespace Tests\Feature;

use App\Filament\Mms\Resources\PartyLeaves\Pages\CreatePartyLeave;
use App\Filament\Mms\Resources\PartyLeaves\Pages\EditPartyLeave;
use App\Models\Party;
use App\Models\PartyLeave;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Vacations are entered per employee — the same person record an
 * assistant's incentive is calculated on.
 */
class PartyLeaveEmployeeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('mms');
        Gate::before(fn () => true);
        $this->actingAs(User::factory()->create());
    }

    public function test_the_form_offers_employees(): void
    {
        $employee = Party::factory()->employee()->create(['name' => 'Amr Employee']);
        $expertOnly = Party::factory()->assistant()->create(['name' => 'Outside Expert']);

        Livewire::test(CreatePartyLeave::class)
            ->assertFormFieldExists('party_id', fn ($field) => array_key_exists($employee->id, $field->getOptions())
                && ! array_key_exists($expertOnly->id, $field->getOptions()))
            ->fillForm(['party_id' => $employee->id, 'start_date' => '2026-10-05', 'end_date' => '2026-10-07', 'reason' => 'Annual'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame($employee->id, PartyLeave::sole()->party_id);
    }

    public function test_an_earlier_vacation_still_shows_who_it_is_for(): void
    {
        $expertOnly = Party::factory()->assistant()->create(['name' => 'Outside Expert']);
        $leave = PartyLeave::create(['party_id' => $expertOnly->id, 'start_date' => '2026-01-05', 'end_date' => '2026-01-06']);

        Livewire::test(EditPartyLeave::class, ['record' => $leave->getRouteKey()])
            ->assertFormFieldExists('party_id', fn ($field) => array_key_exists($expertOnly->id, $field->getOptions()));
    }
}
