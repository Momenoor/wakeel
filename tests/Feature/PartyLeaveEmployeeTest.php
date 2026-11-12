<?php

namespace Tests\Feature;

use App\Enums\LeaveBalanceEntryKind;
use App\Enums\LeaveType;
use App\Filament\Mms\Resources\PartyLeaves\Pages\CreatePartyLeave;
use App\Filament\Mms\Resources\PartyLeaves\Pages\EditPartyLeave;
use App\Filament\Mms\Resources\PartyLeaves\Schemas\PartyLeaveForm;
use App\Models\LeaveBalanceEntry;
use App\Models\Party;
use App\Models\PartyLeave;
use App\Models\Setting;
use App\Models\User;
use App\Services\MMS\LeaveBalanceService;
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
            ->fillForm(['party_id' => $employee->id, 'start_date' => '2026-10-05', 'end_date' => '2026-10-07', 'leave_type' => LeaveType::UNPAID->value, 'reason' => 'Annual'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame($employee->id, PartyLeave::sole()->party_id);
    }

    public function test_an_annual_vacation_takes_its_days_off_the_balance_and_follows_its_changes(): void
    {
        $employee = Party::factory()->employee()->create();
        $balance = app(LeaveBalanceService::class);

        Livewire::test(CreatePartyLeave::class)
            ->fillForm(['party_id' => $employee->id, 'start_date' => '2026-10-05', 'end_date' => '2026-10-07', 'leave_type' => LeaveType::ANNUAL->value])
            ->call('create')
            ->assertHasNoFormErrors();

        $leave = PartyLeave::sole();
        $entry = LeaveBalanceEntry::where('party_leave_id', $leave->id)->sole();
        $this->assertSame(LeaveBalanceEntryKind::TAKEN, $entry->kind);
        $this->assertEquals(-3.0, (float) $entry->days);
        $this->assertSame('2026-10-05', $entry->entry_date->toDateString());
        $this->assertSame(-3.0, $balance->balance($employee->id));
        $this->assertEquals(1.0, (float) $leave->pay_factor);

        // Longer dates move the entry; they do not add another.
        Livewire::test(EditPartyLeave::class, ['record' => $leave->getRouteKey()])
            ->fillForm(['end_date' => '2026-10-09'])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame(-5.0, $balance->balance($employee->id));
        $this->assertSame(1, LeaveBalanceEntry::where('party_id', $employee->id)->count());

        // A type that takes nothing off the balance removes the entry, and its pay factor follows the type.
        Livewire::test(EditPartyLeave::class, ['record' => $leave->getRouteKey()])
            ->fillForm(['leave_type' => LeaveType::UNPAID->value])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame(0.0, $balance->balance($employee->id));
        $this->assertEquals(0.0, (float) $leave->refresh()->pay_factor);

        // Back to annual, then deleted: nothing is left behind.
        $leave->update(['leave_type' => LeaveType::ANNUAL]);
        $this->assertSame(-5.0, $balance->balance($employee->id));
        $leave->delete();
        $this->assertSame(0.0, $balance->balance($employee->id));
    }

    public function test_the_types_settings_payroll_leaves_off_the_balance_take_nothing(): void
    {
        $employee = Party::factory()->employee()->create();
        Setting::set(LeaveType::FROM_BALANCE_SETTING, []);

        PartyLeave::create(['party_id' => $employee->id, 'start_date' => '2026-10-05', 'end_date' => '2026-10-06', 'leave_type' => LeaveType::CASUAL]);
        $this->assertSame(0, LeaveBalanceEntry::count());

        Setting::set(LeaveType::FROM_BALANCE_SETTING, ['casual']);
        PartyLeave::create(['party_id' => $employee->id, 'start_date' => '2026-10-12', 'end_date' => '2026-10-13', 'leave_type' => LeaveType::CASUAL]);
        $this->assertSame(-2.0, app(LeaveBalanceService::class)->balance($employee->id));
    }

    public function test_the_form_says_what_the_type_does_to_the_balance(): void
    {
        $this->assertStringContainsString('3', PartyLeaveForm::balanceEffect(LeaveType::ANNUAL->value, '2026-10-05', '2026-10-07'));
        $this->assertSame(__('This type does not take days off the annual leave balance.'), PartyLeaveForm::balanceEffect(LeaveType::UNPAID->value, '2026-10-05', '2026-10-07'));
    }

    public function test_an_earlier_vacation_still_shows_who_it_is_for(): void
    {
        $expertOnly = Party::factory()->assistant()->create(['name' => 'Outside Expert']);
        $leave = PartyLeave::create(['party_id' => $expertOnly->id, 'start_date' => '2026-01-05', 'end_date' => '2026-01-06']);

        Livewire::test(EditPartyLeave::class, ['record' => $leave->getRouteKey()])
            ->assertFormFieldExists('party_id', fn ($field) => array_key_exists($expertOnly->id, $field->getOptions()));
    }
}
