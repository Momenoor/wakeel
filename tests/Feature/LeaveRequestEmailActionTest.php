<?php

namespace Tests\Feature;

use App\Enums\LeaveType;
use App\Enums\RequestStatus;
use App\Enums\SalaryComponent;
use App\Mail\LeaveRequestDecisionMail;
use App\Mail\LeaveRequestSubmittedMail;
use App\Models\EmployeeProfile;
use App\Models\EmployeeSalaryComponent;
use App\Models\LeaveRequest;
use App\Models\Party;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Approving or rejecting a leave request from the notification email, with no
 * login — the signed URL is the only authentication, the same pattern already
 * used for the matter received-date flow.
 */
class LeaveRequestEmailActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Who hears of a new request: set in System Settings.
        Setting::set('leave_request_recipients', ['redha@jpaemirates.com', 'expert@jpaemirates.com', 'momen.noor@jpaemirates.com', 'info@jpaemirates.com']);
    }

    private function employee(): Party
    {
        $party = Party::factory()->employee()->create(['email' => ['employee@example.com']]);

        EmployeeProfile::create(['party_id' => $party->id, 'date_of_joining' => '2020-01-01', 'opening_leave_balance' => 12.5]);

        EmployeeSalaryComponent::create([
            'party_id' => $party->id,
            'component' => SalaryComponent::BASIC->value,
            'amount' => 6000,
            'effective_from' => '2020-01-01',
        ]);

        return $party->fresh();
    }

    private function request(Party $party, ?LeaveType $type = LeaveType::ANNUAL): LeaveRequest
    {
        return LeaveRequest::create([
            'party_id' => $party->id,
            'status' => RequestStatus::PENDING,
            'start_date' => '2026-06-10',
            'end_date' => '2026-06-14',
            'comment' => 'Family matters',
            'requested_leave_type' => $type,
        ])->fresh();
    }

    public function test_creating_a_request_emails_the_office_with_approve_and_reject_links(): void
    {
        Mail::fake();

        $request = $this->request($this->employee());

        // One mail to them all (each user's own choice applies to theirs).
        Mail::assertQueued(LeaveRequestSubmittedMail::class, 1);
        Mail::assertQueued(
            LeaveRequestSubmittedMail::class,
            fn (LeaveRequestSubmittedMail $mail): bool => $mail->leaveRequest->is($request) && count($mail->to) === 4 && $mail->cc === []
                && collect(['redha@jpaemirates.com', 'expert@jpaemirates.com', 'momen.noor@jpaemirates.com', 'info@jpaemirates.com'])->every(fn (string $address): bool => $mail->hasTo($address)),
        );
    }

    public function test_the_submitted_mail_carries_the_employees_annual_leave_balance(): void
    {
        Mail::fake();

        $party = $this->employee();
        $request = $this->request($party);

        // The running balance: here, just the opening balance.
        $expected = 12.5;

        Mail::assertQueued(
            LeaveRequestSubmittedMail::class,
            fn (LeaveRequestSubmittedMail $mail): bool => $mail->annualLeaveBalance === $expected,
        );
    }

    public function test_annual_leave_shows_the_balance_before_and_after_it_in_both_emails(): void
    {
        Mail::fake();
        $party = $this->employee();
        $request = $this->request($party);
        $days = $request->requestedDays();
        $after = rtrim(rtrim(number_format(12.5 - $days, 1), '0'), '.');

        // Asked for: the days off the balance, the balance before and after.
        $submitted = Mail::queued(LeaveRequestSubmittedMail::class)->first();
        $this->assertSame((float) $days, $submitted->fromBalance);
        $html = $submitted->render();
        $this->assertStringContainsString('الرصيد قبل الإجازة', $html);
        $this->assertMatchesRegularExpression('#'.preg_quote('الرصيد بعد الإجازة', '#').':</td>\s*<td>'.preg_quote($after, '#').'</td>#u', $html);

        // Approved: the same, as taken.
        $this->post(URL::signedRoute('leave-request.email-action.approve', ['leaveRequest' => $request->id]))->assertOk();
        $decision = Mail::queued(LeaveRequestDecisionMail::class)->first();
        $this->assertSame(12.5, $decision->balanceBefore);
        $this->assertMatchesRegularExpression('#'.preg_quote('الرصيد بعد الإجازة', '#').':</td>\s*<td>'.preg_quote($after, '#').'</td>#u', $decision->render());

        // Leave not from the balance: no before and after — its balance as it stands when asked for.
        $sick = $this->request($party, LeaveType::SICK_FULL);
        $mail = Mail::queued(LeaveRequestSubmittedMail::class)->first(fn (LeaveRequestSubmittedMail $mail): bool => $mail->leaveRequest->is($sick));
        $this->assertSame(0.0, $mail->fromBalance);
        $this->assertStringNotContainsString('الرصيد بعد الإجازة', $mail->render());
        $this->assertStringContainsString('رصيد الإجازات', $mail->render());
    }

    public function test_visiting_the_signed_link_only_shows_a_confirmation_and_decides_nothing(): void
    {
        Mail::fake();

        $request = $this->request($this->employee());

        $url = URL::signedRoute('leave-request.email-action.approve', ['leaveRequest' => $request->id]);

        // The whole point of the confirm step: an automated visitor — a mail
        // security scanner following every link in the message, which is
        // exactly what happens with Outlook's Safe Links — must not be able
        // to decide the request just by fetching the URL.
        $this->get($url)->assertOk();

        $this->assertTrue($request->fresh()->isPending());
        Mail::assertNotQueued(LeaveRequestDecisionMail::class);
    }

    public function test_submitting_the_confirmation_approves_without_authentication(): void
    {
        Mail::fake();

        $request = $this->request($this->employee(), LeaveType::CASUAL);

        $url = URL::signedRoute('leave-request.email-action.approve', ['leaveRequest' => $request->id]);

        // No actingAs() — this is the whole point: an unauthenticated click
        // resolves the request.
        $response = $this->post($url);

        $response->assertOk();

        $request = $request->fresh();
        $this->assertSame(RequestStatus::APPROVED, $request->status);
        $this->assertNull($request->approved_by);
        $this->assertSame(1, $request->periods()->count());
        $this->assertSame(LeaveType::CASUAL, $request->periods->first()->leave_type);

        Mail::assertQueued(LeaveRequestDecisionMail::class);
    }

    public function test_submitting_the_confirmation_rejects_without_authentication(): void
    {
        Mail::fake();

        $request = $this->request($this->employee());

        $url = URL::signedRoute('leave-request.email-action.reject', ['leaveRequest' => $request->id]);

        $response = $this->post($url);

        $response->assertOk();

        $request = $request->fresh();
        $this->assertSame(RequestStatus::REJECTED, $request->status);
        $this->assertNull($request->approved_by);

        Mail::assertQueued(LeaveRequestDecisionMail::class);
    }

    public function test_a_link_used_twice_does_not_change_an_already_decided_request(): void
    {
        Mail::fake();

        $request = $this->request($this->employee());

        $url = URL::signedRoute('leave-request.email-action.approve', ['leaveRequest' => $request->id]);

        $this->post($url)->assertOk();
        $this->post($url)->assertOk();

        $this->assertSame(1, $request->fresh()->periods()->count());
    }

    public function test_a_tampered_link_is_refused_on_both_verbs(): void
    {
        $request = $this->request($this->employee());

        $url = URL::signedRoute('leave-request.email-action.approve', ['leaveRequest' => $request->id]).'&tampered=1';

        $this->get($url)->assertForbidden();
        $this->post($url)->assertForbidden();

        $this->assertTrue($request->fresh()->isPending());
    }
}
