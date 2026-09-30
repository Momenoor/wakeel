<?php

namespace Tests\Feature;

use App\Enums\LeaveBalanceEntryKind;
use App\Enums\LeaveType;
use App\Enums\PayrollRunStatus;
use App\Enums\RequestStatus;
use App\Enums\SalaryComponent;
use App\Filament\Mms\Pages\FlightTickets;
use App\Filament\Mms\Resources\EmployeeProfiles\Pages\EditEmployeeProfile;
use App\Filament\Mms\Resources\EmployeeProfiles\RelationManagers\FlightTicketsRelationManager;
use App\Filament\Mms\Resources\EmployeeProfiles\RelationManagers\LeaveBalanceRelationManager;
use App\Filament\Mms\Resources\PayrollRuns\Pages\ViewPayrollRun;
use App\Models\EmployeeProfile;
use App\Models\EmployeeSalaryComponent;
use App\Models\FlightTicket;
use App\Models\LeaveBalanceEntry;
use App\Models\LeaveRequest;
use App\Models\Party;
use App\Models\PayrollRun;
use App\Models\Setting;
use App\Models\User;
use App\Services\MMS\FlightTicketService;
use App\Services\MMS\LeaveBalanceService;
use App\Services\MMS\LeaveRequestService;
use App\Services\MMS\PayrollRunService;
use App\Services\MMS\PayrollService;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * The annual-leave balance as a running record (opening balance, 1 January
 * grants, leave taken — the rest unpaid), and yearly flight tickets paid
 * through payroll.
 */
class LeaveBalanceAndFlightTicketTest extends TestCase
{
    use RefreshDatabase;

    private LeaveBalanceService $balances;

    protected function setUp(): void
    {
        parent::setUp();

        // Tracking began on 30 September 2026.
        Setting::set('leave_balance_started_on', '2026-09-30', 'payroll');
        Setting::clearCache();

        $this->balances = app(LeaveBalanceService::class);
    }

    private function employee(float $opening = 0, string $joined = '2020-01-01', array $profile = []): Party
    {
        $party = Party::factory()->employee()->create();

        EmployeeProfile::create([
            'party_id' => $party->id,
            'date_of_joining' => $joined,
            'opening_leave_balance' => $opening,
            ...$profile,
        ]);

        EmployeeSalaryComponent::create([
            'party_id' => $party->id,
            'component' => SalaryComponent::BASIC->value,
            'amount' => 6000,
            'effective_from' => '2020-01-01',
        ]);

        return $party->fresh();
    }

    private function request(Party $party, string $start, string $end, LeaveType $type = LeaveType::ANNUAL): LeaveRequest
    {
        return LeaveRequest::create([
            'party_id' => $party->id,
            'status' => RequestStatus::PENDING,
            'start_date' => $start,
            'end_date' => $end,
            'requested_leave_type' => $type,
        ])->fresh();
    }

    // ── Leave balance ────────────────────────────────────────────────────────

    public function test_the_balance_starts_from_the_opening_balance_and_follows_the_profile(): void
    {
        $party = $this->employee(12);

        $this->assertSame(12.0, $this->balances->balance($party->id));

        $party->employeeProfile->update(['opening_leave_balance' => 15.5]);

        $this->assertSame(15.5, $this->balances->balance($party->id));
        $this->assertSame(1, LeaveBalanceEntry::where('party_id', $party->id)->count());
    }

    public function test_a_request_beyond_the_balance_uses_the_balance_then_goes_unpaid(): void
    {
        $party = $this->employee(4);
        $request = $this->request($party, '2026-10-05', '2026-10-14'); // 10 days

        $split = app(LeaveRequestService::class)->suggestSplit($request);

        $this->assertSame([
            ['leave_type' => 'annual', 'start_date' => '2026-10-05', 'end_date' => '2026-10-08', 'day_count' => 4.0],
            ['leave_type' => 'unpaid', 'start_date' => '2026-10-09', 'end_date' => '2026-10-14', 'day_count' => 6.0],
        ], $split);

        app(LeaveRequestService::class)->approve($request, User::factory()->create(), $split);

        $this->assertSame(0.0, $this->balances->balance($party->id));
        $this->assertSame(6.0, app(PayrollService::class)->unpaidDaysInPeriod($party->id, Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31')));
    }

    public function test_with_no_balance_the_whole_request_is_unpaid(): void
    {
        $party = $this->employee(0);
        $request = $this->request($party, '2026-10-05', '2026-10-07');

        $this->assertSame([
            ['leave_type' => 'unpaid', 'start_date' => '2026-10-05', 'end_date' => '2026-10-07', 'day_count' => 3.0],
        ], app(LeaveRequestService::class)->suggestSplit($request));
    }

    public function test_annual_days_beyond_the_balance_are_refused(): void
    {
        $party = $this->employee(2);
        $request = $this->request($party, '2026-10-05', '2026-10-07');

        $this->expectException(RuntimeException::class);

        app(LeaveRequestService::class)->approve($request, User::factory()->create(), [
            ['leave_type' => 'annual', 'start_date' => '2026-10-05', 'end_date' => '2026-10-07', 'day_count' => 3],
        ]);
    }

    public function test_thirty_days_are_added_every_first_of_january_once(): void
    {
        $party = $this->employee(10);
        $gone = $this->employee(5, '2019-01-01', ['date_of_leaving' => '2026-12-15']);

        // Not in the year tracking began — the opening balance covers it.
        $this->balances->grantDue(Carbon::parse('2026-10-01'));
        $this->assertSame(10.0, $this->balances->balance($party->id));

        $this->balances->grantDue(Carbon::parse('2027-01-01'));
        $this->balances->grantDue(Carbon::parse('2027-01-02'));

        $this->assertSame(40.0, $this->balances->balance($party->id));
        $this->assertSame(5.0, $this->balances->balance($gone->id));
    }

    public function test_a_new_joiner_gets_the_months_left_in_the_year(): void
    {
        $joiner = $this->employee(0, '2026-11-15');
        $before = $this->employee(0, '2026-03-01'); // joined before tracking began

        $this->artisan('payroll:grant-yearly', ['--date' => '2026-11-20'])->assertSuccessful();

        // November and December: 2 × 2.5 days.
        $this->assertSame(5.0, $this->balances->balance($joiner->id));
        $this->assertSame(0.0, $this->balances->balance($before->id));

        $this->artisan('payroll:grant-yearly', ['--date' => '2027-01-01'])->assertSuccessful();
        $this->assertSame(35.0, $this->balances->balance($joiner->id));
        $this->assertSame(1, LeaveBalanceEntry::where('party_id', $joiner->id)->where('kind', LeaveBalanceEntryKind::JOINING_GRANT)->count());
    }

    // ── Flight tickets ───────────────────────────────────────────────────────

    public function test_entitled_employees_get_a_ticket_every_first_of_january(): void
    {
        $entitled = $this->employee(0, '2020-01-01', ['flight_ticket_entitled' => true, 'flight_ticket_amount' => 2400]);
        $not = $this->employee(0, '2020-01-01', ['flight_ticket_entitled' => false, 'flight_ticket_amount' => 2400]);
        $joiner = $this->employee(0, '2027-04-10', ['flight_ticket_entitled' => true, 'flight_ticket_amount' => 2400]);

        $tickets = app(FlightTicketService::class);
        $this->assertSame(0, $tickets->createDue(Carbon::parse('2026-10-01')));

        $tickets->createDue(Carbon::parse('2027-01-01'));
        $tickets->createDue(Carbon::parse('2027-04-10'));
        $tickets->createDue(Carbon::parse('2027-04-11'));

        $this->assertSame('2400.00', FlightTicket::where('party_id', $entitled->id)->sole()->amount);
        $this->assertFalse(FlightTicket::where('party_id', $not->id)->exists());

        // April to December: 9 of 12 months.
        $prorated = FlightTicket::where('party_id', $joiner->id)->sole();
        $this->assertSame('1800.00', $prorated->amount);
        $this->assertTrue($prorated->is_prorated);
    }

    public function test_a_ticket_added_to_a_run_is_on_the_payslip_and_paid_when_disbursed(): void
    {
        $party = $this->employee();
        $ticket = FlightTicket::create(['party_id' => $party->id, 'year' => 2026, 'amount' => 2000]);
        $run = PayrollRun::create(['period' => '2026-10', 'status' => PayrollRunStatus::DRAFT]);

        app(FlightTicketService::class)->setForRun($run, [$ticket->id]);
        $payslip = app(PayrollService::class)->generate($run)->sole();

        $this->assertSame('2000.00', $payslip->flight_ticket_amount);
        $this->assertSame('8000.00', $payslip->gross);
        $this->assertTrue($payslip->lines()->where('label', 'Flight Ticket 2026')->where('gl_account', FlightTicketService::DEFAULT_GL_ACCOUNT)->exists());
        $this->assertSame($payslip->id, $ticket->fresh()->payslip_id);

        // Regenerating keeps it on the new payslip.
        $payslip = app(PayrollService::class)->generate($run)->sole();
        $this->assertSame($payslip->id, $ticket->fresh()->payslip_id);

        $run->forceFill(['status' => PayrollRunStatus::APPROVED])->save();
        app(PayrollRunService::class)->disburse($run->fresh());

        $ticket->refresh();
        $this->assertTrue($ticket->isPaid());
        $this->assertSame(FlightTicket::PAID_VIA_PAYROLL, $ticket->paid_via);
    }

    public function test_a_ticket_taken_out_of_the_run_is_due_again(): void
    {
        $party = $this->employee();
        $ticket = FlightTicket::create(['party_id' => $party->id, 'year' => 2026, 'amount' => 2000]);
        $run = PayrollRun::create(['period' => '2026-10', 'status' => PayrollRunStatus::DRAFT]);

        app(FlightTicketService::class)->setForRun($run, [$ticket->id]);
        app(PayrollService::class)->generate($run);

        app(FlightTicketService::class)->setForRun($run, []);
        $payslip = app(PayrollService::class)->generate($run)->sole();

        $this->assertSame('0.00', $payslip->flight_ticket_amount);
        $this->assertSame('due', $ticket->fresh()->status()->value);
    }

    public function test_a_ticket_can_be_marked_paid_directly(): void
    {
        $ticket = FlightTicket::create(['party_id' => $this->employee()->id, 'year' => 2026, 'amount' => 2000]);

        app(FlightTicketService::class)->markPaid($ticket, Carbon::parse('2026-10-03'), 'Bought by the office');

        $this->assertSame('paid', $ticket->fresh()->status()->value);
        $this->assertSame(FlightTicket::PAID_VIA_DIRECT, $ticket->fresh()->paid_via);
    }

    public function test_hr_adjusts_the_balance_and_adds_a_ticket_on_the_employee_page(): void
    {
        Gate::before(fn () => true);
        Filament::setCurrentPanel('mms');
        $this->actingAs(User::factory()->create());

        $party = $this->employee(10, '2020-01-01', ['flight_ticket_entitled' => true, 'flight_ticket_amount' => 1800]);
        $owner = ['ownerRecord' => $party->employeeProfile, 'pageClass' => EditEmployeeProfile::class];

        Livewire::test(LeaveBalanceRelationManager::class, $owner)
            ->assertSee(__('Annual leave balance: :days days', ['days' => '10']))
            ->callAction(TestAction::make('adjust')->table(), ['days' => -2.5, 'entry_date' => '2026-10-01', 'note' => 'Correction'])
            ->assertHasNoActionErrors();

        $this->assertSame(7.5, $this->balances->balance($party->id));

        Livewire::test(FlightTicketsRelationManager::class, $owner)
            ->callAction(TestAction::make('create')->table(), ['year' => 2026, 'amount' => 1800])
            ->assertHasNoActionErrors();

        $this->assertSame('1800.00', FlightTicket::where('party_id', $party->id)->sole()->amount);
    }

    public function test_the_flight_tickets_page_lists_every_ticket(): void
    {
        Gate::before(fn () => true);
        Filament::setCurrentPanel('mms');
        $this->actingAs(User::factory()->create());

        $ticket = FlightTicket::create(['party_id' => $this->employee()->id, 'year' => 2026, 'amount' => 1500]);

        $this->get(FlightTickets::getUrl())->assertSuccessful();

        Livewire::test(FlightTickets::class)
            ->assertCanSeeTableRecords([$ticket])
            ->callTableAction('markPaid', $ticket, ['paid_at' => '2026-10-02']);

        $this->assertTrue($ticket->fresh()->isPaid());
    }

    public function test_the_run_page_adds_the_chosen_tickets(): void
    {
        Gate::before(fn () => true);
        Filament::setCurrentPanel('mms');
        $this->actingAs(User::factory()->create());

        $party = $this->employee();
        $ticket = FlightTicket::create(['party_id' => $party->id, 'year' => 2026, 'amount' => 1500]);
        $run = PayrollRun::create(['period' => '2026-10', 'status' => PayrollRunStatus::DRAFT]);
        app(PayrollService::class)->generate($run);

        Livewire::test(ViewPayrollRun::class, ['record' => $run->getRouteKey()])
            ->callAction('flight_tickets', ['tickets' => [$ticket->id]])
            ->assertHasNoActionErrors();

        $this->assertSame($run->id, $ticket->fresh()->payroll_run_id);
        $this->assertSame('1500.00', $run->payslips()->sole()->flight_ticket_amount);
    }
}
