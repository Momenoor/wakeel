<?php

namespace Tests\Feature;

use App\Filament\Mms\Resources\EmployeeProfiles\Pages\ListEmployeeProfiles;
use App\Models\EmployeeProfile;
use App\Models\Party;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Changing several employees at once from the employees list.
 */
class EmployeeProfileBulkActionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Gate::before(fn () => true);
        Filament::setCurrentPanel('mms');
        $this->actingAs(User::factory()->create());
    }

    private function profile(array $attributes = []): EmployeeProfile
    {
        return EmployeeProfile::create([
            'party_id' => Party::factory()->employee()->create()->id,
            'date_of_joining' => '2020-01-01',
            ...$attributes,
        ]);
    }

    public function test_flight_tickets_are_set_for_the_selected_employees(): void
    {
        $a = $this->profile(['flight_ticket_amount' => 1500]);
        $b = $this->profile();
        $untouched = $this->profile();

        Livewire::test(ListEmployeeProfiles::class)
            ->callTableBulkAction('flight_ticket', [$a, $b], ['flight_ticket_entitled' => true, 'flight_ticket_amount' => 2000])
            ->assertHasNoTableBulkActionErrors();

        $this->assertTrue($a->fresh()->flight_ticket_entitled);
        $this->assertSame('2000.00', $a->fresh()->flight_ticket_amount);
        $this->assertTrue($b->fresh()->flight_ticket_entitled);
        $this->assertFalse($untouched->fresh()->flight_ticket_entitled);

        // No amount given: each keeps their own.
        Livewire::test(ListEmployeeProfiles::class)
            ->callTableBulkAction('flight_ticket', [$a], ['flight_ticket_entitled' => true, 'flight_ticket_amount' => null]);

        $this->assertSame('2000.00', $a->fresh()->flight_ticket_amount);
    }

    public function test_settings_are_switched_and_employees_marked_as_left(): void
    {
        $a = $this->profile(['include_in_salary_authorization_form' => true, 'is_eosg_applicable' => true]);
        $b = $this->profile(['include_in_salary_authorization_form' => true, 'is_eosg_applicable' => true]);

        Livewire::test(ListEmployeeProfiles::class)
            ->callTableBulkAction('salary_form', [$a, $b], ['value' => false])
            ->callTableBulkAction('eosg', [$a], ['value' => false])
            ->callTableBulkAction('mark_left', [$b], ['date_of_leaving' => '2026-09-30']);

        $this->assertFalse($a->fresh()->include_in_salary_authorization_form);
        $this->assertFalse($b->fresh()->include_in_salary_authorization_form);
        $this->assertFalse($a->fresh()->is_eosg_applicable);
        $this->assertTrue($b->fresh()->is_eosg_applicable);
        $this->assertSame('2026-09-30', $b->fresh()->date_of_leaving->toDateString());
    }

    public function test_selected_employees_can_be_deleted(): void
    {
        $a = $this->profile();
        $keep = $this->profile();

        Livewire::test(ListEmployeeProfiles::class)
            ->callTableBulkAction('delete', [$a]);

        $this->assertSoftDeleted($a);
        $this->assertNotSoftDeleted($keep);
    }
}
