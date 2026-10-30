<?php

namespace Tests\Feature;

use App\Enums\LeaveType;
use App\Enums\LoanKind;
use App\Enums\PayrollRunStatus;
use App\Enums\SalaryComponent;
use App\Models\EmployeeLoan;
use App\Models\EmployeeProfile;
use App\Models\EmployeeSalaryComponent;
use App\Models\Party;
use App\Models\PartyLeave;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\PayslipLine;
use App\Models\Setting;
use App\Services\MMS\LoanScheduleService;
use App\Services\MMS\PayrollJournalVoucherService;
use App\Services\MMS\PayrollService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * The monthly payroll engine, end to end.
 *
 * The scenario throughout is one employee on 6,000 basic with 4,000 of
 * allowances, because that split is where the mistakes live: gratuity and unpaid
 * days are computed on the 6,000, while gross pay and the bank transfer are
 * computed on the 10,000. A single figure for "salary" would pass most tests and
 * be wrong in production.
 */
class PayrollRunTest extends TestCase
{
    use RefreshDatabase;

    private PayrollService $payroll;

    protected function setUp(): void
    {
        parent::setUp();

        // Setting caches its values (a request-cache plus a persistent store)
        // independently of the DB transaction RefreshDatabase rolls back, so a
        // value set by one test would otherwise leak into the next.
        Setting::clearCache();

        $this->payroll = app(PayrollService::class);
    }

    private function employee(array $profile = []): Party
    {
        $party = Party::factory()->employee()->create();

        EmployeeProfile::create([
            'party_id' => $party->id,
            'date_of_joining' => '2020-01-01',
            'bank_name' => 'Emirates NBD',
            'iban' => 'AE070331234567890123456',
            ...$profile,
        ]);

        foreach ([
            SalaryComponent::BASIC->value => 6000,
            SalaryComponent::HOUSING->value => 3000,
            SalaryComponent::TRANSPORT->value => 1000,
        ] as $component => $amount) {
            EmployeeSalaryComponent::create([
                'party_id' => $party->id,
                'component' => $component,
                'amount' => $amount,
                'effective_from' => '2020-01-01',
            ]);
        }

        return $party->fresh();
    }

    private function payrollRun(string $period = '2026-06'): PayrollRun
    {
        return PayrollRun::create(['period' => $period, 'status' => PayrollRunStatus::DRAFT]);
    }

    public function test_it_pays_gross_when_nothing_is_deducted(): void
    {
        $this->employee();

        $payslips = $this->payroll->generate($this->payrollRun());

        $this->assertCount(1, $payslips);
        // The run's one line in the activity log — not one per payslip, line and instalment.
        $this->assertSame(0, Activity::query()->whereIn('subject_type', [(new Payslip)->getMorphClass(), (new PayslipLine)->getMorphClass()])->count());

        $payslip = $payslips->first();

        $this->assertSame('6000.00', $payslip->basic_snapshot);
        $this->assertSame('4000.00', $payslip->allowances_snapshot);
        $this->assertSame('10000.00', $payslip->gross);
        $this->assertSame('10000.00', $payslip->net_pay);
        $this->assertFalse($payslip->needs_review);
    }

    public function test_a_run_reads_once_for_everyone_and_logs_once(): void
    {
        // A run of the month with so many more employees, each with a loan instalment due: its queries.
        $queries = function (int $more, string $period): int {
            foreach (range(1, $more) as $i) {
                $loan = EmployeeLoan::create(['party_id' => $this->employee()->id, 'kind' => LoanKind::LOAN, 'principal' => 600, 'months' => 6, 'starts_on' => '2026-06-01']);
                app(LoanScheduleService::class)->generateFor($loan);
            }
            $run = $this->payrollRun($period);

            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->payroll->generate($run);
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        $two = $queries(2, '2026-06');
        $five = $queries(3, '2026-07');

        // Each employee more: their payslip and instalment written — three queries at most — nothing read again.
        $this->assertLessThanOrEqual($two + 3 * 3, $five);
        $this->assertSame(5, Payslip::query()->whereHas('payrollRun', fn ($q) => $q->where('period', '2026-07'))->count());

        // One entry in the activity log for each run, not one for each payslip, line and instalment.
        $this->assertSame(2, Activity::query()->where('description', 'Payslips generated')->count());
        $this->assertSame(0, Activity::query()->where('subject_type', (new PayslipLine)->getMorphClass())->count());
    }

    public function test_only_parties_holding_the_employee_role_are_paid(): void
    {
        $this->employee();

        // An assistant expert with a profile and a salary is still not an
        // employee: the payroll module must see only the employee role.
        $assistant = Party::factory()->assistant()->create();
        EmployeeProfile::create(['party_id' => $assistant->id, 'date_of_joining' => '2020-01-01']);

        $payslips = $this->payroll->generate($this->payrollRun());

        $this->assertCount(1, $payslips);
        $this->assertNotSame($assistant->id, $payslips->first()->party_id);
    }

    public function test_an_employee_without_a_profile_is_not_paid(): void
    {
        Party::factory()->employee()->create();

        $this->assertCount(0, $this->payroll->generate($this->payrollRun()));
    }

    public function test_unpaid_leave_is_deducted_at_the_whole_salary_over_thirty(): void
    {
        $party = $this->employee();

        PartyLeave::create([
            'party_id' => $party->id,
            'start_date' => '2026-06-10',
            'end_date' => '2026-06-14',
            'leave_type' => LeaveType::UNPAID,
            'pay_factor' => 0,
        ]);

        $payslip = $this->payroll->generate($this->payrollRun())->first();

        // Five days of the whole salary — basic and allowances, 10,000/30 = 333.33 a day — not of the basic alone
        // (6,000/30 = 200 a day).
        $this->assertSame('5.0', $payslip->unpaid_days);
        $this->assertSame('1666.67', $payslip->unpaid_deduction);
        // Paid to the whole dirham: 8,333.33 rounded up.
        $this->assertSame('8334.00', $payslip->net_pay);
        $this->assertSame('0.67', $payslip->rounding);
    }

    public function test_half_pay_sick_leave_withholds_half_a_day_per_day(): void
    {
        $party = $this->employee();

        PartyLeave::create([
            'party_id' => $party->id,
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-04',
            'leave_type' => LeaveType::SICK_HALF,
            'pay_factor' => 0.5,
        ]);

        $payslip = $this->payroll->generate($this->payrollRun())->first();

        $this->assertSame('2.0', $payslip->unpaid_days);
        $this->assertSame('666.67', $payslip->unpaid_deduction);
    }

    public function test_leave_recorded_before_this_module_existed_costs_nothing(): void
    {
        $party = $this->employee();

        // No leave_type, no pay_factor: a row hand-entered to explain an absence,
        // not to dock anyone. Reading it as unpaid would invent a deduction out
        // of history — and there are such rows in production already.
        PartyLeave::create([
            'party_id' => $party->id,
            'start_date' => '2026-06-10',
            'end_date' => '2026-06-14',
            'reason' => 'Annual leave',
        ]);

        $payslip = $this->payroll->generate($this->payrollRun())->first();

        $this->assertSame('0.0', $payslip->unpaid_days);
        $this->assertSame('10000.00', $payslip->net_pay);
    }

    public function test_leave_straddling_the_month_end_is_counted_only_for_its_days_inside(): void
    {
        $party = $this->employee();

        PartyLeave::create([
            'party_id' => $party->id,
            'start_date' => '2026-06-28',
            'end_date' => '2026-07-05',
            'leave_type' => LeaveType::UNPAID,
            'pay_factor' => 0,
        ]);

        $payslip = $this->payroll->generate($this->payrollRun('2026-06'))->first();

        // 28, 29, 30 June — July's five days belong to July's payslip.
        $this->assertSame('3.0', $payslip->unpaid_days);
    }

    public function test_a_loan_instalment_is_deducted_and_marked_as_recovered(): void
    {
        $party = $this->employee();

        $loan = EmployeeLoan::create([
            'party_id' => $party->id,
            'kind' => LoanKind::LOAN,
            'principal' => 10000,
            'months' => 6,
            'starts_on' => '2026-06-01',
        ]);

        app(LoanScheduleService::class)->generateFor($loan);

        $payslip = $this->payroll->generate($this->payrollRun('2026-06'))->first();

        // Month one carries the rounding remainder: 10,000 − 1,650 × 5.
        $this->assertSame('1750.00', $payslip->loan_deduction);
        $this->assertSame('8250.00', $payslip->net_pay);

        $this->assertSame(
            $payslip->id,
            $loan->installments()->where('seq', 1)->value('payslip_id'),
        );
        $this->assertEqualsWithDelta(8250.0, $loan->fresh()->outstanding(), 0.005);
    }

    public function test_regenerating_a_draft_releases_the_instalments_it_had_taken(): void
    {
        $party = $this->employee();

        $loan = EmployeeLoan::create([
            'party_id' => $party->id,
            'kind' => LoanKind::LOAN,
            'principal' => 10000,
            'months' => 6,
            'starts_on' => '2026-06-01',
        ]);

        app(LoanScheduleService::class)->generateFor($loan);

        $run = $this->payrollRun('2026-06');

        $this->payroll->generate($run);
        $second = $this->payroll->generate($run)->first();

        // The second pass must deduct the same instalment, not skip it as
        // already recovered and not double-count it either.
        $this->assertSame('1750.00', $second->loan_deduction);
        $this->assertSame(1, $loan->installments()->whereNotNull('payslip_id')->count());
        $this->assertSame(1, Payslip::count());
    }

    public function test_regenerating_preserves_hand_entered_figures(): void
    {
        $this->employee();

        $run = $this->payrollRun();

        // manual_deduction has no source to be re-derived from, and an
        // incentive marked as overridden is a figure somebody chose on purpose.
        $this->payroll->generate($run)->first()->forceFill([
            'manual_deduction' => 500,
            'incentive_amount' => 250,
            'incentive_overridden' => true,
        ])->save();

        $payslip = $this->payroll->generate($run)->first();

        $this->assertSame('500.00', $payslip->manual_deduction);
        $this->assertSame('250.00', $payslip->incentive_amount);
        $this->assertTrue($payslip->incentive_overridden);
        $this->assertSame('10250.00', $payslip->gross);
        $this->assertSame('9750.00', $payslip->net_pay);
    }

    public function test_an_unflagged_incentive_is_re_derived_on_regeneration(): void
    {
        $this->employee();

        $run = $this->payrollRun();

        // Not marked as overridden, so it came from an import — and an import
        // that survived a regeneration would keep paying an incentive after the
        // calculation behind it was corrected or withdrawn.
        $this->payroll->generate($run)->first()
            ->forceFill(['incentive_amount' => 250])->save();

        $payslip = $this->payroll->generate($run)->first();

        $this->assertSame('0.00', $payslip->incentive_amount);
    }

    public function test_deductions_beyond_gross_are_flagged_rather_than_clamped(): void
    {
        $this->employee();

        $run = $this->payrollRun();

        $this->payroll->generate($run)->first()
            ->forceFill(['manual_deduction' => 12000])->save();

        $payslip = $this->payroll->generate($run)->first();

        $this->assertTrue($payslip->needs_review);
        $this->assertSame('-2000.00', $payslip->net_pay);
    }

    public function test_gratuity_accrues_on_basic_and_is_not_deducted_from_the_employee(): void
    {
        $this->employee();

        $payslip = $this->payroll->generate($this->payrollRun())->first();

        // Six years in, a month is worth roughly 30/12 days of the 200 daily
        // basic rate. What matters here is that it is charged to the employer:
        // net pay is untouched by it.
        $this->assertGreaterThan(0, (float) $payslip->eosg_accrued);
        $this->assertSame('10000.00', $payslip->net_pay);
    }

    public function test_gratuity_is_not_posted_to_the_monthly_salaries_voucher(): void
    {
        $this->employee();

        $run = $this->payrollRun();
        $this->payroll->generate($run);

        // Still accrued and stored on the payslip (asserted above) — it just
        // does not reach the monthly voucher any more. It is posted once a
        // year instead, by EndOfServiceGratuityClosingVoucherService.
        $voucher = app(PayrollJournalVoucherService::class)->forRun($run);

        $accounts = [...array_column($voucher['debits'], 'account'), ...array_column($voucher['credits'], 'account')];

        $this->assertNotContains(PayrollService::GL_EOSG_EXPENSE, $accounts);
        $this->assertNotContains(PayrollJournalVoucherService::GL_EOSG_PROVISION, $accounts);
    }

    public function test_an_employee_not_applicable_for_eosg_accrues_nothing(): void
    {
        $party = $this->employee();
        $party->employeeProfile->forceFill(['is_eosg_applicable' => false])->save();

        $payslip = $this->payroll->generate($this->payrollRun())->first();

        $this->assertSame('0.00', $payslip->eosg_accrued);
    }

    public function test_an_employee_who_left_before_the_period_is_not_paid(): void
    {
        $this->employee(['date_of_leaving' => '2026-01-31']);

        $this->assertCount(0, $this->payroll->generate($this->payrollRun('2026-06')));
    }

    public function test_an_employee_hired_after_the_period_is_not_paid(): void
    {
        $this->employee(['date_of_joining' => '2026-09-01']);

        $this->assertCount(0, $this->payroll->generate($this->payrollRun('2026-06')));
    }

    public function test_a_run_past_draft_cannot_be_regenerated(): void
    {
        $this->employee();

        $run = $this->payrollRun();
        $this->payroll->generate($run);

        $run->forceFill(['status' => PayrollRunStatus::HR_REVIEW])->save();

        $this->expectException(RuntimeException::class);

        $this->payroll->generate($run->fresh());
    }

    public function test_a_loan_is_locked_for_editing_once_an_instalment_is_taken(): void
    {
        $party = $this->employee();

        $loan = EmployeeLoan::create([
            'party_id' => $party->id,
            'kind' => LoanKind::LOAN,
            'principal' => 10000,
            'months' => 6,
            'starts_on' => '2026-06-01',
        ]);

        app(LoanScheduleService::class)->generateFor($loan);

        $this->assertTrue($loan->isEditable());

        $this->payroll->generate($this->payrollRun('2026-06'));

        // The first instalment is now part of a payslip. Changing the amount or
        // the term would rewrite a schedule that has already been partly
        // recovered, so the whole advance closes to editing.
        $this->assertFalse($loan->fresh()->isEditable());
        $this->assertTrue($loan->fresh()->hasDeductedInstallments());
    }

    public function test_the_schedule_cannot_be_rebuilt_once_an_instalment_is_taken(): void
    {
        $party = $this->employee();

        $loan = EmployeeLoan::create([
            'party_id' => $party->id,
            'kind' => LoanKind::LOAN,
            'principal' => 10000,
            'months' => 6,
            'starts_on' => '2026-06-01',
        ]);

        app(LoanScheduleService::class)->generateFor($loan);
        $this->payroll->generate($this->payrollRun('2026-06'));

        $this->expectException(InvalidArgumentException::class);

        app(LoanScheduleService::class)->generateFor($loan->fresh());
    }

    public function test_the_voucher_itemises_loan_clearing_by_employee(): void
    {
        $first = $this->employee();
        $second = $this->employee();

        foreach ([$first, $second] as $party) {
            $loan = EmployeeLoan::create([
                'party_id' => $party->id,
                'kind' => LoanKind::LOAN,
                'principal' => 1200,
                'months' => 6,
                'starts_on' => '2026-06-01',
            ]);

            app(LoanScheduleService::class)->generateFor($loan);
        }

        $run = $this->payrollRun();
        $this->payroll->generate($run);

        $voucher = app(PayrollJournalVoucherService::class)->forRun($run);

        $loanCredits = array_values(array_filter(
            $voucher['credits'],
            fn (array $line): bool => $line['account'] === 'Staff Loans Receivable',
        ));

        // One line each, named. A single lump credit balances just as well but
        // cannot be reconciled against a loan ledger that is kept per person.
        $this->assertCount(2, $loanCredits);
        $this->assertEqualsCanonicalizing(
            [$first->name, $second->name],
            array_column($loanCredits, 'detail'),
        );

        $this->assertTrue($voucher['balanced']);
    }

    public function test_everything_but_loan_clearing_stays_summarised(): void
    {
        $this->employee();
        $this->employee();

        $run = $this->payrollRun();
        $this->payroll->generate($run);

        $voucher = app(PayrollJournalVoucherService::class)->forRun($run);

        $salaries = array_values(array_filter(
            $voucher['debits'],
            fn (array $line): bool => $line['account'] === 'Salaries Expense',
        ));

        // Two employees at 10,000 (basic + allowances) each, one merged
        // Salaries Expense line — the expense side reconciles against the
        // payroll register, not against individuals.
        $this->assertCount(1, $salaries);
        $this->assertNull($salaries[0]['detail']);
        $this->assertSame(20000.0, $salaries[0]['amount']);
    }

    public function test_a_payslip_carries_every_instalment_behind_its_loan_figure(): void
    {
        $party = $this->employee();

        // Two concurrent advances: a staff loan and a petty cash float.
        foreach ([[LoanKind::LOAN, 1200], [LoanKind::PETTY_CASH, 600]] as [$kind, $principal]) {
            $loan = EmployeeLoan::create([
                'party_id' => $party->id,
                'kind' => $kind,
                'principal' => $principal,
                'months' => 6,
                'starts_on' => '2026-06-01',
            ]);

            app(LoanScheduleService::class)->generateFor($loan);
        }

        $payslip = $this->payroll->generate($this->payrollRun())->first();

        // One figure on the payslip, two instalments behind it — which is why
        // the column opens a breakdown rather than just showing a total.
        $this->assertSame('300.00', $payslip->loan_deduction);
        $this->assertSame(2, $payslip->installments()->count());
    }

    public function test_a_new_run_rounds_each_net_pay_up_to_the_dirham_and_one_of_before_keeps_its_figures(): void
    {
        $party = $this->employee();
        PartyLeave::create(['party_id' => $party->id, 'start_date' => '2026-06-10', 'end_date' => '2026-06-10', 'leave_type' => LeaveType::UNPAID, 'pay_factor' => 0]);

        // 10,000 less a day of it (333.33): 9,666.67, paid 9,667.00.
        $new = $this->payrollRun();
        $this->assertTrue($new->round_net_up);
        $payslip = $this->payroll->generate($new)->first();
        $this->assertSame(['9667.00', '0.33'], [$payslip->net_pay, $payslip->rounding]);

        // The rounding an expense line of its own in the voucher, the voucher still balanced.
        $voucher = app(PayrollJournalVoucherService::class)->forRun($new);
        $this->assertTrue($voucher['balanced']);
        $this->assertSame(0.33, collect($voucher['debits'])->firstWhere('account', PayrollJournalVoucherService::GL_ROUNDING)['amount']);
        $this->assertSame(9667.0, collect($voucher['credits'])->firstWhere('account', 'Net Salary Payable')['amount']);

        // A run of before, generated again: its figures as they were, no rounding.
        $old = $this->payrollRun('2026-07');
        $old->forceFill(['round_net_up' => false])->save();
        PartyLeave::create(['party_id' => $party->id, 'start_date' => '2026-07-10', 'end_date' => '2026-07-10', 'leave_type' => LeaveType::UNPAID, 'pay_factor' => 0]);
        $payslip = $this->payroll->generate($old)->first();
        $this->assertSame(['9666.67', '0.00'], [$payslip->net_pay, $payslip->rounding]);
        $this->assertNull(collect(app(PayrollJournalVoucherService::class)->forRun($old)['debits'])->firstWhere('account', PayrollJournalVoucherService::GL_ROUNDING));

        // A whole figure, or one below nothing, as it is.
        $this->assertSame([10000.0, 0.0], PayrollService::roundedUp($new, 10000.0));
        $this->assertSame([-12.5, 0.0], PayrollService::roundedUp($new, -12.5));
        $this->assertSame([1501.0, 0.99], PayrollService::roundedUp($new, 1500.01));
    }

    public function test_the_journal_voucher_balances(): void
    {
        $party = $this->employee();

        PartyLeave::create([
            'party_id' => $party->id,
            'start_date' => '2026-06-10',
            'end_date' => '2026-06-14',
            'leave_type' => LeaveType::UNPAID,
            'pay_factor' => 0,
        ]);

        $loan = EmployeeLoan::create([
            'party_id' => $party->id,
            'kind' => LoanKind::PETTY_CASH,
            'principal' => 1200,
            'months' => 6,
            'starts_on' => '2026-06-01',
        ]);

        app(LoanScheduleService::class)->generateFor($loan);

        $run = $this->payrollRun();
        $this->payroll->generate($run);

        $voucher = app(PayrollJournalVoucherService::class)->forRun($run);

        $this->assertTrue($voucher['balanced'], 'Journal voucher does not balance.');
        $this->assertSame($voucher['total_debit'], $voucher['total_credit']);
        $this->assertSame(1, $voucher['employee_count']);

        $accounts = array_column($voucher['debits'], 'account');
        $this->assertContains('Salaries Expense', $accounts);

        $credits = array_column($voucher['credits'], 'account');
        $this->assertContains('Net Salary Payable', $credits);
        $this->assertContains('Petty Cash Advances', $credits);
        // Unpaid leave lowers the salaries expense: no account of its own, nothing credited back to salaries.
        $this->assertNotContains('Unpaid Leave Recovery', $credits);
        $this->assertNotContains('Salaries Expense', $credits);

        $payslip = $run->payslips()->first();
        $salaries = collect($voucher['debits'])->firstWhere('account', 'Salaries Expense')['amount'];
        $this->assertGreaterThan(0, (float) $payslip->unpaid_deduction);
        $this->assertSame(round(10000 - (float) $payslip->unpaid_deduction, 2), $salaries);
    }

    public function test_the_voucher_debits_gross_not_net(): void
    {
        $this->employee();

        $run = $this->payrollRun();
        $this->payroll->generate($run);

        $voucher = app(PayrollJournalVoucherService::class)->forRun($run);

        $salaryAndAllowances = array_sum(array_map(
            fn (array $line): float => str_contains($line['account'], 'EOSG') ? 0.0 : $line['amount'],
            $voucher['debits'],
        ));

        // The expense side must reconcile against the payroll register, which is
        // gross. Posting net would leave every deduction account unexplained.
        $this->assertSame(10000.0, $salaryAndAllowances);
    }

    public function test_the_bank_fee_debits_an_expense_and_folds_into_net_salary_payable(): void
    {
        Setting::set('payroll_bank_fee_amount', 25);

        $this->employee();

        $run = $this->payrollRun();
        $this->payroll->generate($run);

        $voucher = app(PayrollJournalVoucherService::class)->forRun($run);

        $bankFeeDebits = array_values(array_filter(
            $voucher['debits'],
            fn (array $line): bool => $line['account'] === PayrollJournalVoucherService::GL_BANK_FEES_EXPENSE,
        ));
        $this->assertCount(1, $bankFeeDebits);
        $this->assertSame(25.0, $bankFeeDebits[0]['amount']);

        // No separate "Bank Fees Payable" line — it settles in the same
        // transfer as net pay, so it folds into that one payable figure.
        $accounts = array_column($voucher['credits'], 'account');
        $this->assertNotContains('Bank Fees Payable', $accounts);

        $payable = array_values(array_filter(
            $voucher['credits'],
            fn (array $line): bool => $line['account'] === PayrollJournalVoucherService::GL_NET_SALARY_PAYABLE,
        ));
        $this->assertCount(1, $payable);
        $this->assertSame(10025.0, $payable[0]['amount']);

        $this->assertTrue($voucher['balanced']);
        $this->assertSame($voucher['total_debit'], $voucher['total_credit']);
    }

    public function test_no_bank_fee_line_appears_when_the_setting_is_zero(): void
    {
        $this->employee();

        $run = $this->payrollRun();
        $this->payroll->generate($run);

        $voucher = app(PayrollJournalVoucherService::class)->forRun($run);

        $accounts = [...array_column($voucher['debits'], 'account'), ...array_column($voucher['credits'], 'account')];

        $this->assertNotContains(PayrollJournalVoucherService::GL_BANK_FEES_EXPENSE, $accounts);
    }

    public function test_the_bank_fee_is_fixed_regardless_of_headcount(): void
    {
        Setting::set('payroll_bank_fee_amount', 25);

        $this->employee();
        $this->employee();

        $run = $this->payrollRun();
        $this->payroll->generate($run);

        $voucher = app(PayrollJournalVoucherService::class)->forRun($run);

        $bankFeeDebits = array_values(array_filter(
            $voucher['debits'],
            fn (array $line): bool => $line['account'] === PayrollJournalVoucherService::GL_BANK_FEES_EXPENSE,
        ));

        // One flat charge, not one per employee.
        $this->assertCount(1, $bankFeeDebits);
        $this->assertSame(25.0, $bankFeeDebits[0]['amount']);
    }
}
