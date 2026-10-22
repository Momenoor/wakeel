<?php

namespace Tests\Feature;

use App\Enums\AttachmentCategory;
use App\Enums\EmployeeRequestType;
use App\Enums\LoanKind;
use App\Enums\LoanStatus;
use App\Enums\NotificationType;
use App\Enums\RequestStatus;
use App\Enums\SalaryComponent;
use App\Filament\Mms\Resources\EmployeeRequests\Pages\CreateEmployeeRequest;
use App\Filament\Mms\Resources\EmployeeRequests\Pages\ListEmployeeRequests;
use App\Filament\Mms\Resources\EmployeeRequests\Pages\ViewEmployeeRequest;
use App\Filament\Mms\Resources\LeaveRequests\LeaveRequestResource;
use App\Mail\EmployeeRequestDecisionMail;
use App\Mail\EmployeeRequestSubmittedMail;
use App\Models\EmployeeLoan;
use App\Models\EmployeeProfile;
use App\Models\EmployeeRequest;
use App\Models\EmployeeSalaryComponent;
use App\Models\FlightTicket;
use App\Models\LetterTemplate;
use App\Models\Party;
use App\Models\Setting;
use App\Models\User;
use App\Services\MMS\EmployeePlaceholders;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Employee requests: a loan or petty cash — how much, over how many months, from which month's salary — or a
 * certificate from a template; decided as leave is (in the panel with changes, or from the email's links);
 * money becomes the advance payroll deducts only once the accountant marks it paid; a certificate is issued
 * on approval, for the employee to download.
 */
class EmployeeRequestsTest extends TestCase
{
    use RefreshDatabase;

    private User $employee;

    private User $approver;

    private User $accountant;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('mms'));
        $this->travelTo(now()->setDate(2026, 10, 22));
        Setting::set('leave_request_recipients', ['hr@office.test']);

        $permissions = fn (string ...$names) => collect($names)->map(fn (string $name) => Permission::findOrCreate($name))->all();
        $this->employee = User::factory()->create(['email' => 'amr@office.test'])->givePermissionTo($permissions('ViewAny:EmployeeRequest', 'View:EmployeeRequest', 'Create:EmployeeRequest', 'Update:EmployeeRequest', 'Delete:EmployeeRequest'));
        $party = Party::factory()->employee()->create(['name' => 'Amr Hassan', 'user_id' => $this->employee->id, 'email' => ['amr@office.test']]);
        EmployeeProfile::create(['party_id' => $party->id, 'date_of_joining' => '2021-03-01', 'designation' => 'Expert assistant', 'passport_no' => 'P123456']);
        EmployeeSalaryComponent::create(['party_id' => $party->id, 'component' => SalaryComponent::BASIC->value, 'amount' => 8000, 'effective_from' => '2021-03-01']);
        EmployeeSalaryComponent::create(['party_id' => $party->id, 'component' => SalaryComponent::HOUSING->value, 'amount' => 4500, 'effective_from' => '2021-03-01']);

        $this->approver = User::factory()->create(['email' => 'hr@office.test'])->givePermissionTo($permissions('ViewAny:EmployeeRequest', 'View:EmployeeRequest', 'Approve:EmployeeRequest'));
        $this->accountant = User::factory()->create(['email' => 'accounts@office.test'])->givePermissionTo($permissions('ViewAny:EmployeeRequest', 'View:EmployeeRequest', 'Pay:EmployeeRequest'));
    }

    private function party(): Party
    {
        return $this->employee->fresh()->party;
    }

    public function test_an_employee_asks_for_a_loan_and_the_approvers_hear_of_it(): void
    {
        Mail::fake();
        $this->actingAs($this->employee);
        $someoneElse = Party::factory()->employee()->create();

        Livewire::test(CreateEmployeeRequest::class)
            ->fillForm(['party_id' => $someoneElse->id, 'type' => EmployeeRequestType::LOAN->value, 'amount' => 3000, 'months' => 6, 'starts_on' => '2026-12-01', 'reason' => 'Car repair'])
            ->assertSee('500.00')
            ->call('create')
            ->assertHasNoFormErrors();

        // Their own, pending, filed by them — whatever the form sent.
        $request = EmployeeRequest::sole();
        $this->assertSame([$this->party()->id, RequestStatus::PENDING, $this->employee->id, '3000.00', 6, '2026-12-01'],
            [$request->party_id, $request->status, $request->requested_by, $request->amount, $request->months, $request->starts_on->toDateString()]);

        Mail::assertQueued(EmployeeRequestSubmittedMail::class, fn (EmployeeRequestSubmittedMail $mail): bool => $mail->hasTo('hr@office.test'));
        $this->assertSame(1, $this->approver->notifications()->where('data->viewData->'.NotificationType::DATA_KEY, NotificationType::EMPLOYEE_REQUEST_SUBMITTED->value)->count());

        // Theirs only on the list.
        $other = EmployeeRequest::create(['party_id' => $someoneElse->id, 'type' => EmployeeRequestType::PETTY_CASH, 'status' => RequestStatus::PENDING, 'amount' => 200, 'months' => 1, 'starts_on' => '2026-11-01']);
        Livewire::test(ListEmployeeRequests::class)->assertCanSeeTableRecords([$request])->assertCanNotSeeTableRecords([$other]);
    }

    public function test_approved_with_changes_it_becomes_the_advance_payroll_deducts_only_once_paid(): void
    {
        Mail::fake();
        $request = EmployeeRequest::create(['party_id' => $this->party()->id, 'type' => EmployeeRequestType::LOAN, 'status' => RequestStatus::PENDING, 'amount' => 3000, 'months' => 6, 'starts_on' => '2026-11-01']);

        // Approved for less, over longer, from later.
        $this->actingAs($this->approver);
        Livewire::test(ListEmployeeRequests::class)
            ->callAction(TestAction::make('approve')->table($request), ['amount' => 2400, 'months' => 8, 'starts_on' => '2027-01-01', 'approved_comment' => 'Over eight months'])
            ->assertHasNoActionErrors();

        $request->refresh();
        $this->assertSame([RequestStatus::APPROVED, '2400.00', 8, '2027-01-01', $this->approver->id], [$request->status, $request->amount, $request->months, $request->starts_on->toDateString(), $request->approved_by]);
        // Not paid yet: no advance, nothing to deduct.
        $this->assertSame(0, EmployeeLoan::count());
        $this->assertTrue($request->awaitsPayment());
        // The employee told, the accountant asked to pay it.
        Mail::assertQueued(EmployeeRequestDecisionMail::class, fn (EmployeeRequestDecisionMail $mail): bool => $mail->hasTo('amr@office.test'));
        $this->assertSame(1, $this->accountant->notifications()->where('data->viewData->'.NotificationType::DATA_KEY, NotificationType::EMPLOYEE_REQUEST_TO_PAY->value)->count());

        // Paid: the advance, its instalments from the month approved.
        $this->actingAs($this->accountant);
        Livewire::test(ViewEmployeeRequest::class, ['record' => $request->getKey()])
            ->callAction('markPaid', ['paid_on' => '2026-10-22', 'payment_note' => 'Transfer 991'])
            ->assertHasNoActionErrors();

        $loan = EmployeeLoan::sole();
        $this->assertSame([LoanKind::LOAN, LoanStatus::ACTIVE, '2400.00', 8, $this->party()->id], [$loan->kind, $loan->status, $loan->principal, $loan->months, $loan->party_id]);
        $this->assertSame('2027-01', $loan->installments()->orderBy('seq')->value('due_period'));
        $this->assertSame(2400.0, (float) $loan->installments()->sum('amount'));
        $this->assertSame([$loan->id, '2026-10-22', $this->accountant->id], [$request->fresh()->employee_loan_id, $request->fresh()->paid_on->toDateString(), $request->fresh()->paid_by]);
        $this->assertFalse($request->fresh()->awaitsPayment());
    }

    public function test_rejected_with_its_reason_and_decided_only_once(): void
    {
        Mail::fake();
        $request = EmployeeRequest::create(['party_id' => $this->party()->id, 'type' => EmployeeRequestType::PETTY_CASH, 'status' => RequestStatus::PENDING, 'amount' => 500, 'months' => 1, 'starts_on' => '2026-11-01']);

        $this->actingAs($this->approver);
        Livewire::test(ViewEmployeeRequest::class, ['record' => $request->getKey()])
            ->callAction('reject', ['approved_comment' => 'Budget closed this month'])
            ->assertActionHidden('approve');

        $this->assertSame([RequestStatus::REJECTED, 'Budget closed this month'], [$request->fresh()->status, $request->fresh()->approved_comment]);
        $this->assertSame(0, $this->accountant->notifications()->count());
    }

    public function test_approved_from_the_emails_link_as_asked(): void
    {
        Mail::fake();
        $request = EmployeeRequest::create(['party_id' => $this->party()->id, 'type' => EmployeeRequestType::PETTY_CASH, 'status' => RequestStatus::PENDING, 'amount' => 750, 'months' => 1, 'starts_on' => '2026-11-01']);
        $url = URL::signedRoute('employee-request.email-action.approve', ['employeeRequest' => $request->id]);

        // GET only asks; POST decides.
        $this->get($url)->assertOk()->assertSee('Amr Hassan')->assertSee('750.00');
        $this->assertTrue($request->fresh()->isPending());
        $this->post($url)->assertOk();
        $this->assertSame(RequestStatus::APPROVED, $request->fresh()->status);
        $this->post($url)->assertOk()->assertSee(__('This link has already been used. The request status is: '));
        // Unsigned: nothing.
        $this->post(route('employee-request.email-action.reject', ['employeeRequest' => $request->id]))->assertForbidden();
    }

    public function test_a_certificate_is_issued_from_its_template_on_approval_for_the_employee_to_download(): void
    {
        Mail::fake();
        $template = LetterTemplate::where('slug', 'salary-certificate')->sole();
        $request = EmployeeRequest::create(['party_id' => $this->party()->id, 'type' => EmployeeRequestType::CERTIFICATE, 'status' => RequestStatus::PENDING, 'letter_template_id' => $template->id, 'addressed_to' => 'Emirates NBD', 'purpose' => 'opening a bank account']);

        // What its placeholders hold.
        $values = EmployeePlaceholders::values($request);
        $this->assertSame(['Amr Hassan', 'Expert assistant', '01/03/2021', '12,500.00', '8,000.00', 'Emirates NBD'],
            [$values['employee.name'], $values['employee.designation'], $values['employee.joining_date'], $values['employee.salary.total'], $values['employee.salary.basic'], $values['request.addressed_to']]);
        $this->assertNotSame('', $values['employee.salary.total.words']);

        $this->actingAs($this->approver);
        Livewire::test(ListEmployeeRequests::class)
            ->callAction(TestAction::make('approve')->table($request), ['letter_template_id' => $template->id, 'addressed_to' => 'Emirates NBD — Deira'])
            ->assertHasNoActionErrors();

        $certificate = $request->fresh()->certificate();
        $this->assertNotNull($certificate);
        $this->assertSame(AttachmentCategory::CERTIFICATE, $certificate->category);
        $this->assertStringStartsWith('%PDF', (string) $certificate->storage()->get($certificate->path));
        $this->assertSame('HR/2026/'.str_pad((string) $request->id, 4, '0', STR_PAD_LEFT), $request->fresh()->reference);
        // Sent with the decision.
        Mail::assertQueued(EmployeeRequestDecisionMail::class, fn (EmployeeRequestDecisionMail $mail): bool => count($mail->attachments()) === 1);

        // The employee downloads it; another employee may not.
        $this->actingAs($this->employee)->get($certificate->url())->assertOk();
        $this->actingAs(User::factory()->create()->givePermissionTo(Permission::findOrCreate('View:EmployeeRequest')))->get($certificate->url())->assertForbidden();
    }

    public function test_a_salary_advance_is_at_most_the_salary_of_its_months_and_paid_back_from_it(): void
    {
        Mail::fake();
        $this->actingAs($this->employee);

        // 12,500 a month: 13,000 over one month is too much.
        Livewire::test(CreateEmployeeRequest::class)
            ->fillForm(['type' => EmployeeRequestType::SALARY_ADVANCE->value, 'amount' => 13000, 'months' => 1, 'starts_on' => '2026-11-01'])
            ->assertSee('12,500.00')
            ->call('create')
            ->assertHasFormErrors(['amount']);

        Livewire::test(CreateEmployeeRequest::class)
            ->fillForm(['type' => EmployeeRequestType::SALARY_ADVANCE->value, 'amount' => 5000, 'months' => 1, 'starts_on' => '2026-11-01'])
            ->call('create')
            ->assertHasNoFormErrors();

        $request = EmployeeRequest::sole();
        $this->actingAs($this->approver);
        Livewire::test(ViewEmployeeRequest::class, ['record' => $request->getKey()])->callAction('approve', ['amount' => 5000, 'months' => 1, 'starts_on' => '2026-11-01']);
        $this->actingAs($this->accountant);
        Livewire::test(ViewEmployeeRequest::class, ['record' => $request->getKey()])->callAction('markPaid', ['paid_on' => '2026-10-22']);

        $loan = EmployeeLoan::sole();
        $this->assertSame([LoanKind::SALARY_ADVANCE, '5000.00', '2026-11'], [$loan->kind, $loan->principal, $loan->installments()->value('due_period')]);
    }

    public function test_a_ticket_advance_pays_the_years_ticket_which_payroll_then_does_not_pay_again(): void
    {
        Mail::fake();
        $this->party()->employeeProfile->update(['flight_ticket_entitled' => true, 'flight_ticket_amount' => 2800]);
        $this->actingAs($this->employee);

        // For what the year's ticket is.
        Livewire::test(CreateEmployeeRequest::class)
            ->fillForm(['type' => EmployeeRequestType::TICKET_ADVANCE->value, 'ticket_year' => 2026, 'amount' => null])
            ->call('create')
            ->assertHasNoFormErrors();
        $request = EmployeeRequest::sole();
        $this->assertSame(['2800.00', 2026, null], [$request->amount, $request->ticket_year, $request->months]);

        $this->actingAs($this->approver);
        Livewire::test(ViewEmployeeRequest::class, ['record' => $request->getKey()])->callAction('approve', ['ticket_year' => 2026, 'amount' => 2800]);
        $this->actingAs($this->accountant);
        Livewire::test(ViewEmployeeRequest::class, ['record' => $request->getKey()])->callAction('markPaid', ['paid_on' => '2026-10-22', 'payment_note' => 'Cash']);

        // The year's ticket paid directly; nothing to deduct, nothing for payroll to pay.
        $ticket = FlightTicket::sole();
        $this->assertSame([2026, '2800.00', FlightTicket::PAID_VIA_DIRECT, '2026-10-22'], [$ticket->year, $ticket->amount, $ticket->paid_via, $ticket->paid_at->toDateString()]);
        $this->assertSame($ticket->id, $request->fresh()->flight_ticket_id);
        $this->assertSame(0, EmployeeLoan::count());
        $this->assertSame(0, FlightTicket::unpaid()->count());

        // Not asked for twice.
        $this->actingAs($this->employee);
        Livewire::test(CreateEmployeeRequest::class)
            ->fillForm(['type' => EmployeeRequestType::TICKET_ADVANCE->value, 'ticket_year' => 2026, 'amount' => 2800])
            ->call('create')
            ->assertHasFormErrors(['ticket_year']);
    }

    public function test_leave_is_asked_for_from_here_too_on_its_own_form(): void
    {
        $this->employee->givePermissionTo(Permission::findOrCreate('ViewAny:LeaveRequest'), Permission::findOrCreate('Create:LeaveRequest'));
        $this->actingAs($this->employee->fresh());

        Livewire::test(CreateEmployeeRequest::class)
            ->set('data.type', 'leave')
            ->assertRedirect(LeaveRequestResource::getUrl('create'));
        Livewire::test(ListEmployeeRequests::class)->assertActionVisible('newLeave');

        // Without leave to ask for, it is not offered.
        $this->actingAs($this->approver);
        Livewire::test(ListEmployeeRequests::class)->assertActionHidden('newLeave');
    }

    public function test_certificate_templates_are_not_offered_as_a_matters_letters(): void
    {
        $this->assertSame(2, LetterTemplate::query()->certificates()->count());
        $this->assertSame(0, LetterTemplate::query()->letters()->whereIn('slug', ['salary-certificate', 'no-objection-certificate'])->count());
    }
}
