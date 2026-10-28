<?php

namespace Tests\Feature;

use App\Enums\NotificationChannel as Channel;
use App\Enums\NotificationType as Type;
use App\Enums\RequestStatus;
use App\Enums\RequestType;
use App\Enums\SalaryComponent;
use App\Mail\LeaveRequestDecisionMail;
use App\Mail\LeaveRequestSubmittedMail;
use App\Mail\NewMatterNotificationMail;
use App\Mail\NewRequestNotificationMail;
use App\Mail\RequestActionNotificationMail;
use App\Mail\UnmatchedEventReferencesMail;
use App\Models\CalendarEvent;
use App\Models\EmployeeProfile;
use App\Models\EmployeeSalaryComponent;
use App\Models\LeaveRequest;
use App\Models\Matter;
use App\Models\MatterParty;
use App\Models\MatterRequest;
use App\Models\Party;
use App\Models\Setting;
use App\Models\User;
use App\Models\WhatsAppTemplate;
use App\Services\MMS\LeaveRequestService;
use App\Services\MMS\NewMatterNotification;
use App\Services\MMS\Requests\RequestServiceFactory;
use App\Services\Notifications\NotificationPreferences as Prefs;
use BezhanSalleh\FilamentShield\Support\Utils;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The notifications about requests, new matters, leave and the calendar reach each user by the
 * channels they want (a role can make one mandatory) — an email of its own to each, not one to all.
 */
class NotificationPreferencesRespectedTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array> WhatsApp messages sent */
    private array $whatsapp = [];

    protected function setUp(): void
    {
        parent::setUp();
        // The WhatsApp messages go after the answer (defer()): sent at once here, to be seen.
        $this->withoutDefer();

        app()->setLocale('en');
        Mail::fake();
        // Admins hold no permission here; the bell links are only checked for being sent.
        Gate::before(fn () => true);
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => Utils::getSuperAdminName(), 'guard_name' => 'web']);

        config(['services.whatsapp.token' => 't', 'services.whatsapp.phone_id' => '99']);
        Http::fake(function (Request $request) {
            $this->whatsapp[] = $request->data();

            return Http::response(['messages' => [['id' => 'wamid.1']]]);
        });
        WhatsAppTemplate::create(['name' => 'Notification', 'purpose' => WhatsAppTemplate::NOTIFICATION, 'meta_name' => 'user_notification', 'language' => 'ar', 'header' => 'none', 'body' => '{{title}}', 'is_active' => true, 'is_default' => true,
            'parameters' => [['name' => 'title', 'value' => '{{title}}'], ['name' => 'body', 'value' => '{{body}}'], ['name' => 'link', 'value' => '{{link}}']]]);
    }

    private function user(string $name, ?string $role = null, ?string $phone = null): User
    {
        $user = User::factory()->create(['name' => $name, 'display_name' => $name]);

        if ($role) {
            $user->assignRole($role);
        }

        if ($phone) {
            Party::factory()->create(['user_id' => $user->id, 'phone' => [$phone]]);
        }

        return $user->refresh();
    }

    /** What the user chose: channel => on. */
    private function choose(User $user, Type $type, array $channels): void
    {
        Prefs::make()->saveUser($user->refresh(), [$type->value => $channels]);
    }

    /** Mandatory for everyone of the role. */
    private function lock(string $role, Type $type, Channel ...$channels): void
    {
        Prefs::make()->saveRole(Role::findByName($role), [$type->value => collect($channels)->mapWithKeys(fn (Channel $channel) => [$channel->value => Prefs::LOCKED])->all()]);
    }

    private function bellTypes(User $user): array
    {
        return $user->notifications()->get()->map(fn ($n) => $n->data['viewData'][Type::DATA_KEY] ?? null)->all();
    }

    /** @return list<string> the phones WhatsApp messages went to */
    private function whatsappTo(): array
    {
        return array_column($this->whatsapp, 'to');
    }

    private function matterWithAssistants(User ...$assistants): Matter
    {
        $matter = Matter::factory()->create(['number' => '125', 'year' => '2026', 'distributed_at' => now()]);

        foreach ($assistants as $user) {
            $party = Party::factory()->assistant()->create(['user_id' => $user->id, 'email' => [$user->email], 'phone' => ['0501112223']]);
            MatterParty::create(['matter_id' => $matter->id, 'party_id' => $party->id, 'role' => 'expert', 'type' => 'assistant']);
        }

        return $matter;
    }

    private function request(Matter $matter, RequestType $type = RequestType::CHANGE_DIFFICULTY, ?User $by = null): MatterRequest
    {
        return MatterRequest::create([
            'matter_id' => $matter->id, 'request_by' => ($by ?? User::factory()->create())->id, 'type' => $type,
            'status' => RequestStatus::PENDING, 'comment' => 'x', 'extra' => ['new_difficulty' => 'hard'],
        ]);
    }

    // --- A new request ----------------------------------------------------------------------

    public function test_a_new_request_goes_to_each_admin_by_what_they_chose_and_one_email_each(): void
    {
        $default = $this->user('Default', 'admin');
        $second = $this->user('Second', 'admin');
        $muted = $this->user('Muted', 'admin');
        $this->choose($muted, Type::REQUEST_CREATED, ['bell' => false, 'mail' => false]);
        $super = $this->user('Super', Utils::getSuperAdminName());

        RequestServiceFactory::make($this->request($this->matterWithAssistants($this->user('Assistant'))))->onCreateNotify();

        // An email of its own to each who wants it — never one with everybody in To:.
        Mail::assertSent(NewRequestNotificationMail::class, 3);
        foreach ([$default, $second, $super] as $user) {
            Mail::assertSent(NewRequestNotificationMail::class, fn ($mail) => $mail->hasTo($user->email) && count($mail->to) === 1);
        }
        Mail::assertNotSent(NewRequestNotificationMail::class, fn ($mail) => $mail->hasTo($muted->email));
        // The bell, with its type kept.
        $this->assertSame([Type::REQUEST_CREATED->value], $this->bellTypes($default));
        $this->assertSame([], $this->bellTypes($muted));
        // WhatsApp is off until someone turns it on.
        $this->assertSame([], $this->whatsappTo());
    }

    public function test_a_new_request_on_whatsapp_goes_to_those_who_turned_it_on_only(): void
    {
        $on = $this->user('On', 'admin', '0501234567');
        $this->user('Off', 'admin', '0507654321');
        $this->choose($on, Type::REQUEST_CREATED, ['whatsapp' => true]);

        RequestServiceFactory::make($this->request($this->matterWithAssistants($this->user('Assistant'))))->onCreateNotify();

        $this->assertSame(['971501234567'], $this->whatsappTo());
        $this->assertSame('new_request_notification', $this->whatsapp[0]['template']['name']);
    }

    public function test_a_role_can_make_a_new_request_mandatory(): void
    {
        $muted = $this->user('Muted', 'admin');
        $this->choose($muted, Type::REQUEST_CREATED, ['bell' => false, 'mail' => false]);
        $this->lock('admin', Type::REQUEST_CREATED, Channel::BELL, Channel::MAIL);

        RequestServiceFactory::make($this->request($this->matterWithAssistants($this->user('Assistant'))))->onCreateNotify();

        Mail::assertSent(NewRequestNotificationMail::class, fn ($mail) => $mail->hasTo($muted->email));
        $this->assertSame([Type::REQUEST_CREATED->value], $this->bellTypes($muted));
    }

    // --- A request decided ------------------------------------------------------------------

    public function test_a_decision_goes_to_each_assistant_by_what_they_chose(): void
    {
        $first = $this->user('First');
        $second = $this->user('Second');
        $quiet = $this->user('Quiet');
        $this->choose($quiet, Type::REQUEST_DECIDED, ['bell' => false, 'mail' => false]);
        $talker = $this->user('Talker');
        $this->choose($talker, Type::REQUEST_DECIDED, ['whatsapp' => true]);
        Party::factory()->create(['user_id' => $talker->id, 'phone' => ['0509998887']]);
        $matter = $this->matterWithAssistants($first, $second, $quiet, $talker);

        RequestServiceFactory::make($this->request($matter))->reject(['approved_comment' => 'no', 'attachments' => []]);

        // One email each, to those who want it.
        Mail::assertSent(RequestActionNotificationMail::class, 3);
        foreach ([$first, $second, $talker] as $user) {
            Mail::assertSent(RequestActionNotificationMail::class, fn ($mail) => $mail->hasTo($user->email) && count($mail->to) === 1);
        }
        $this->assertSame([Type::REQUEST_DECIDED->value], $this->bellTypes($first));
        $this->assertSame([], $this->bellTypes($quiet));
        // WhatsApp (the generic message): the one who chose it.
        $this->assertSame(['971509998887'], $this->whatsappTo());
        $this->assertSame('Request Rejected', $this->whatsapp[0]['template']['components'][0]['parameters'][0]['text']);
    }

    public function test_a_role_can_make_a_decision_mandatory(): void
    {
        $assistant = $this->user('Muted', 'admin');
        $this->choose($assistant, Type::REQUEST_DECIDED, ['bell' => false, 'mail' => false]);
        $this->lock('admin', Type::REQUEST_DECIDED, Channel::BELL, Channel::MAIL);

        RequestServiceFactory::make($this->request($this->matterWithAssistants($assistant)))->approve(['approved_comment' => 'ok', 'attachments' => []]);

        Mail::assertSent(RequestActionNotificationMail::class, fn ($mail) => $mail->hasTo($assistant->email));
        $this->assertSame([Type::REQUEST_DECIDED->value], $this->bellTypes($assistant));
    }

    public function test_changes_requested_is_its_own_notification(): void
    {
        $asked = $this->user('Asked');
        $other = $this->user('Other');
        // Muting a decision does not mute changes requested.
        $this->choose($other, Type::REQUEST_DECIDED, ['bell' => false, 'mail' => false]);
        $this->choose($other, Type::REQUEST_CHANGES, ['mail' => false]);
        $matter = $this->matterWithAssistants($asked, $other);

        RequestServiceFactory::make($this->request($matter, RequestType::REVIEW_REPORT))->requestChanges(['approved_comment' => 'Fix the dates']);

        $this->assertSame([Type::REQUEST_CHANGES->value], $this->bellTypes($asked));
        $this->assertSame([Type::REQUEST_CHANGES->value], $this->bellTypes($other));
        Mail::assertSent(RequestActionNotificationMail::class, 1);
        Mail::assertSent(RequestActionNotificationMail::class, fn ($mail) => $mail->hasTo($asked->email));
    }

    // --- A matter assigned ------------------------------------------------------------------

    public function test_a_new_matter_reaches_the_assistant_by_what_they_chose(): void
    {
        $default = $this->user('Default');
        $muted = $this->user('Muted');
        $this->choose($muted, Type::MATTER_ASSIGNED, ['bell' => false, 'mail' => false]);
        $talker = $this->user('Talker');
        $this->choose($talker, Type::MATTER_ASSIGNED, ['whatsapp' => true]);
        $matter = $this->matterWithAssistants($default, $muted, $talker);

        app(NewMatterNotification::class)->sendToAssistants($matter->fresh());

        Mail::assertQueued(NewMatterNotificationMail::class, 2);
        Mail::assertQueued(NewMatterNotificationMail::class, fn ($mail) => $mail->hasTo($default->email) && count($mail->to) === 1);
        Mail::assertQueued(NewMatterNotificationMail::class, fn ($mail) => $mail->hasTo($talker->email));
        $this->assertSame([Type::MATTER_ASSIGNED->value], $this->bellTypes($default));
        $this->assertSame([], $this->bellTypes($muted));
        // The request to confirm the received date is made for each regardless.
        $this->assertSame(3, MatterRequest::query()->where('type', RequestType::CHANGE_DISTRIBUTED_DATE)->count());
        $this->assertContains('971501112223', $this->whatsappTo());
    }

    public function test_an_assistant_with_no_user_still_gets_the_request_and_the_email(): void
    {
        // Only a party with an email: no user to be the requester, and no preferences to follow.
        $matter = Matter::factory()->create(['number' => '126', 'year' => '2026', 'distributed_at' => now()]);
        $party = Party::factory()->assistant()->create(['user_id' => null, 'email' => ['outside@firm.ae']]);
        MatterParty::create(['matter_id' => $matter->id, 'party_id' => $party->id, 'role' => 'expert', 'type' => 'assistant']);

        app(NewMatterNotification::class)->sendToAssistants($matter->fresh());

        $request = MatterRequest::query()->where('matter_id', $matter->id)->where('type', RequestType::CHANGE_DISTRIBUTED_DATE)->sole();
        $this->assertNull($request->request_by);
        $this->assertNull($request->requestBy);
        Mail::assertQueued(NewMatterNotificationMail::class, fn ($mail) => $mail->hasTo('outside@firm.ae'));
    }

    public function test_a_role_can_make_a_new_matter_email_mandatory(): void
    {
        $muted = $this->user('Muted', 'admin');
        $this->choose($muted, Type::MATTER_ASSIGNED, ['mail' => false]);
        $this->lock('admin', Type::MATTER_ASSIGNED, Channel::MAIL);
        $matter = $this->matterWithAssistants($muted);

        app(NewMatterNotification::class)->sendToAssistants($matter->fresh());

        Mail::assertQueued(NewMatterNotificationMail::class, fn ($mail) => $mail->hasTo($muted->email));
    }

    // --- Leave ------------------------------------------------------------------------------

    private function leaveRequest(Party $party): LeaveRequest
    {
        return LeaveRequest::create([
            'party_id' => $party->id, 'status' => RequestStatus::PENDING,
            'start_date' => '2026-10-05', 'end_date' => '2026-10-06', 'comment' => 'Family',
        ])->fresh();
    }

    private function employee(?User $user = null, array $email = ['employee@example.com']): Party
    {
        $party = Party::factory()->employee()->create(['user_id' => $user?->id, 'email' => $email, 'phone' => ['0504445556']]);
        EmployeeProfile::create(['party_id' => $party->id, 'date_of_joining' => '2020-01-01']);
        EmployeeSalaryComponent::create(['party_id' => $party->id, 'component' => SalaryComponent::BASIC->value, 'amount' => 6000, 'effective_from' => '2020-01-01']);

        return $party->fresh();
    }

    public function test_a_new_leave_request_reaches_the_users_by_what_they_chose_and_other_addresses_as_always(): void
    {
        $default = $this->user('Default');
        $muted = $this->user('Muted');
        $this->choose($muted, Type::LEAVE_SUBMITTED, ['bell' => false, 'mail' => false]);
        $mailOnly = $this->user('MailOnly');
        $this->choose($mailOnly, Type::LEAVE_SUBMITTED, ['bell' => false]);
        Setting::set('leave_request_recipients', [$default->email, $muted->email, $mailOnly->email, 'outside@firm.ae']);

        $this->leaveRequest($this->employee());

        // One email to them all, with the approve/reject links — the muted user's left out.
        Mail::assertQueued(LeaveRequestSubmittedMail::class, 1);
        Mail::assertQueued(LeaveRequestSubmittedMail::class, fn ($mail) => count($mail->to) === 3 && $mail->approveUrl !== ''
            && $mail->hasTo($default->email) && $mail->hasTo($mailOnly->email) && $mail->hasTo('outside@firm.ae'));
        Mail::assertNotQueued(LeaveRequestSubmittedMail::class, fn ($mail) => $mail->hasTo($muted->email));
        $this->assertSame([Type::LEAVE_SUBMITTED->value], $this->bellTypes($default));
        $this->assertSame([], $this->bellTypes($muted));
        $this->assertSame([], $this->bellTypes($mailOnly));
    }

    public function test_a_role_can_make_a_new_leave_request_mandatory(): void
    {
        $muted = $this->user('Muted', 'admin');
        $this->choose($muted, Type::LEAVE_SUBMITTED, ['bell' => false, 'mail' => false]);
        $this->lock('admin', Type::LEAVE_SUBMITTED, Channel::BELL, Channel::MAIL);
        Setting::set('leave_request_recipients', [$muted->email]);

        $this->leaveRequest($this->employee());

        Mail::assertQueued(LeaveRequestSubmittedMail::class, fn ($mail) => $mail->hasTo($muted->email));
        $this->assertSame([Type::LEAVE_SUBMITTED->value], $this->bellTypes($muted));
    }

    public function test_the_employee_gets_the_decision_email_for_a_decision_in_the_panel_too(): void
    {
        $employee = $this->user('Employee');
        $service = app(LeaveRequestService::class);
        $request = $this->leaveRequest($this->employee($employee));

        $service->approve($request, User::factory()->create(), $service->suggestSplit($request));

        Mail::assertQueued(LeaveRequestDecisionMail::class, 1);
        Mail::assertQueued(LeaveRequestDecisionMail::class, fn ($mail) => $mail->hasTo('employee@example.com'));
        $this->assertSame([Type::LEAVE_DECIDED->value], $this->bellTypes($employee));
    }

    public function test_a_decision_by_the_email_link_sends_the_employee_one_email_only(): void
    {
        $employee = $this->user('Employee');
        $request = $this->leaveRequest($this->employee($employee));

        $this->post(URL::signedRoute('leave-request.email-action.reject', ['leaveRequest' => $request->id]))->assertOk();

        Mail::assertQueued(LeaveRequestDecisionMail::class, 1);
        $this->assertCount(1, $employee->notifications);
    }

    public function test_an_employee_who_muted_the_decision_is_not_told_by_it_and_a_role_can_make_it_mandatory(): void
    {
        $employee = $this->user('Employee', 'admin', '0501239999');
        $this->choose($employee, Type::LEAVE_DECIDED, ['bell' => false, 'mail' => false, 'whatsapp' => true]);
        $service = app(LeaveRequestService::class);
        $party = $this->employee($employee);

        $first = $this->leaveRequest($party);
        $service->reject($first, User::factory()->create(), 'No.');
        $this->post(URL::signedRoute('leave-request.email-action.reject', ['leaveRequest' => $this->leaveRequest($party)->id]))->assertOk();

        Mail::assertNothingQueued();
        $this->assertSame([], $this->bellTypes($employee));
        // WhatsApp, which they turned on: the number of their party (the first one found for the user).
        $this->assertCount(2, $this->whatsapp);

        $this->lock('admin', Type::LEAVE_DECIDED, Channel::BELL, Channel::MAIL);
        $service->reject($this->leaveRequest($party), User::factory()->create(), 'Again.');

        Mail::assertQueued(LeaveRequestDecisionMail::class, 1);
        $this->assertSame([Type::LEAVE_DECIDED->value], $this->bellTypes($employee));
    }

    public function test_an_employee_with_no_user_gets_the_decision_email_however_it_was_decided(): void
    {
        $service = app(LeaveRequestService::class);
        $party = $this->employee();

        // Decided in the panel: the email to the party's address.
        $request = $this->leaveRequest($party);
        $service->reject($request, User::factory()->create(), 'No.');
        Mail::assertQueued(LeaveRequestDecisionMail::class, 1);

        // Decided by the link: the same, once.
        $this->post(URL::signedRoute('leave-request.email-action.reject', ['leaveRequest' => $this->leaveRequest($party)->id]))->assertOk();
        Mail::assertQueued(LeaveRequestDecisionMail::class, 2);
    }

    public function test_the_decision_email_goes_to_the_employee_the_office_copied_in(): void
    {
        $employee = $this->user('Employee');
        $muted = $this->user('Muted');
        $this->choose($muted, Type::LEAVE_SUBMITTED, ['mail' => false]);
        Setting::set('leave_request_recipients', ['hr@office.ae', 'boss@office.ae', $muted->email]);
        $service = app(LeaveRequestService::class);

        $service->approve($request = $this->leaveRequest($this->employee($employee)), User::factory()->create(), $service->suggestSplit($request));

        // One email: the employee its To, the office — as each chose — its CC.
        Mail::assertQueued(LeaveRequestDecisionMail::class, 1);
        Mail::assertQueued(LeaveRequestDecisionMail::class, fn ($mail) => count($mail->to) === 1 && $mail->hasTo('employee@example.com')
            && count($mail->cc) === 2 && $mail->hasCc('hr@office.ae') && $mail->hasCc('boss@office.ae'));

        // The employee not told by email (as they chose): the office still is, to them.
        $this->choose($employee, Type::LEAVE_DECIDED, ['mail' => false]);
        $service->reject($this->leaveRequest($this->employee($employee)), User::factory()->create(), 'No.');
        Mail::assertQueued(LeaveRequestDecisionMail::class, fn ($mail) => $mail->hasTo('hr@office.ae') && $mail->hasTo('boss@office.ae') && ! $mail->hasTo('employee@example.com') && $mail->cc === []);
    }

    // --- The calendar -----------------------------------------------------------------------

    public function test_the_unmatched_calendar_report_goes_to_the_admins_who_want_it_by_email(): void
    {
        $wants = $this->user('Wants', 'admin');
        $muted = $this->user('Muted', 'admin');
        $this->choose($muted, Type::CALENDAR_UNMATCHED, ['mail' => false]);
        CalendarEvent::create(['title' => 'Session 6399/2025', 'start_datetime' => now()->addDays(2), 'end_datetime' => now()->addDays(2)->addHour(), 'type' => 'single']);

        $this->artisan('calendar:report-unmatched')->assertSuccessful();

        Mail::assertSent(UnmatchedEventReferencesMail::class, 1);
        Mail::assertSent(UnmatchedEventReferencesMail::class, fn ($mail) => $mail->hasTo($wants->email));

        // A role can make it mandatory.
        $this->lock('admin', Type::CALENDAR_UNMATCHED, Channel::MAIL);
        $this->artisan('calendar:report-unmatched')->assertSuccessful();

        Mail::assertSent(UnmatchedEventReferencesMail::class, fn ($mail) => $mail->hasTo($muted->email));
    }
}
