<?php

namespace Tests\Feature;

use App\Enums\RequestStatus;
use App\Enums\SalaryComponent;
use App\Livewire\ChatWidget;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\EmployeeProfile;
use App\Models\EmployeeSalaryComponent;
use App\Models\LeaveRequest;
use App\Models\Matter;
use App\Models\MatterParty;
use App\Models\Party;
use App\Models\PushSubscription;
use App\Models\Setting;
use App\Models\User;
use App\Services\MMS\LeaveRequestService;
use App\Services\MMS\NewMatterNotification;
use App\Services\Notify\UserAlert;
use App\Services\Push\WebPushSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Everything the system tells people reaches the bell, the desktop and
 * their phones: matter requests (already), a new matter, leave requests and
 * their decisions, and chat messages.
 */
class SystemNotificationsTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array{0: int, 1: array<string, mixed>}> */
    private array $pushed = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Chat has its own page permission; these users hold it.
        Gate::before(fn ($user, string $ability) => $ability === 'View:Chat' ? true : null);

        app()->setLocale('en');
        Mail::fake();
        $this->withoutDefer();

        $pushed = &$this->pushed;
        $this->app->instance(WebPushSender::class, new class($pushed) extends WebPushSender
        {
            public function __construct(private array &$pushed) {}

            public function sendToUser(int $userId, array $payload): int
            {
                $this->pushed[] = [$userId, $payload];

                return 1;
            }
        });
    }

    private function subscribed(User $user): User
    {
        PushSubscription::create([
            'user_id' => $user->id,
            'endpoint' => 'https://push.test/'.$user->id,
            'endpoint_hash' => PushSubscription::hashEndpoint('https://push.test/'.$user->id),
            'public_key' => 'BKey',
            'auth_token' => 'auth',
        ]);

        return $user;
    }

    private function titlesFor(User $user): array
    {
        return $user->notifications()->get()->pluck('data.title')->all();
    }

    public function test_a_chat_message_is_pushed_to_the_other_person_only(): void
    {
        $me = $this->subscribed(User::factory()->create(['name' => 'Amr']));
        $other = $this->subscribed(User::factory()->create());
        $conversation = ChatConversation::betweenUsers($me, $other);

        $this->actingAs($me);
        Livewire::test(ChatWidget::class, ['mode' => 'popup'])
            ->call('selectConversation', $conversation->id)
            ->call('sendMessage', 'Are you in the office?');

        $this->assertCount(1, $this->pushed);
        [$userId, $payload] = $this->pushed[0];
        $this->assertSame($other->id, $userId);
        $this->assertSame('Are you in the office?', $payload['body']);
        $this->assertSame('wakeel-chat-'.$conversation->id, $payload['tag']);
        $this->assertTrue($payload['renotify']);
        // In the bell as one entry for the sender — and pushed once, by the
        // chat itself: the entry is not sent again as a push.
        $this->assertSame(1, $other->notifications()->count());
        $this->assertSame(0, $me->notifications()->count());
    }

    public function test_a_received_chat_message_is_offered_as_a_desktop_notification(): void
    {
        $me = User::factory()->create();
        $ali = User::factory()->create(['name' => 'Ali']);
        $conversation = ChatConversation::betweenUsers($me, $ali);

        $this->actingAs($me);
        $widget = Livewire::test(ChatWidget::class, ['mode' => 'popup']);

        ChatMessage::create(['chat_conversation_id' => $conversation->id, 'user_id' => $ali->id, 'body' => 'Hello']);

        $widget->call('checkForNewMessages')
            ->assertDispatched('wakeel-desktop-notification', id: 'chat-'.$conversation->id, body: 'Hello');
    }

    public function test_an_assistant_is_told_about_a_new_matter(): void
    {
        $user = User::factory()->create();
        $party = Party::factory()->assistant()->create(['user_id' => $user->id, 'email' => ['amr@firm.ae']]);
        $matter = Matter::factory()->create(['year' => 2026, 'number' => 125, 'distributed_at' => now()]);
        MatterParty::create(['matter_id' => $matter->id, 'party_id' => $party->id, 'role' => 'expert', 'type' => 'assistant']);

        app(NewMatterNotification::class)->sendToAssistants($matter->fresh());

        $this->assertContains('New matter assigned', $this->titlesFor($user));
        $body = $user->notifications()->sole()->data['body'];
        $this->assertStringContainsString('125/2026', $body);
    }

    private function leaveRequest(User $employeeUser): LeaveRequest
    {
        $party = Party::factory()->employee()->create(['user_id' => $employeeUser->id]);
        EmployeeProfile::create(['party_id' => $party->id, 'date_of_joining' => '2020-01-01']);
        EmployeeSalaryComponent::create(['party_id' => $party->id, 'component' => SalaryComponent::BASIC->value, 'amount' => 6000, 'effective_from' => '2020-01-01']);

        return LeaveRequest::create([
            'party_id' => $party->id,
            'status' => RequestStatus::PENDING,
            'start_date' => '2026-10-05',
            'end_date' => '2026-10-06',
            'comment' => 'Family',
        ])->fresh();
    }

    public function test_the_office_is_told_about_a_new_leave_request(): void
    {
        // Whoever System Settings names (else those who approve leave).
        Setting::set('leave_request_recipients', ['hr@office.test']);
        $office = User::factory()->create(['email' => 'hr@office.test']);
        $someoneElse = User::factory()->create();

        $this->leaveRequest(User::factory()->create());

        $this->assertContains('New leave request', $this->titlesFor($office));
        $this->assertSame([], $this->titlesFor($someoneElse));
    }

    public function test_the_employee_is_told_the_decision(): void
    {
        $employee = User::factory()->create();
        $service = app(LeaveRequestService::class);

        $approved = $this->leaveRequest($employee);
        $service->approve($approved, User::factory()->create(), $service->suggestSplit($approved));

        $rejected = $this->leaveRequest($employee);
        $service->reject($rejected, User::factory()->create(), 'Court hearing that week.');

        $titles = $this->titlesFor($employee);
        $this->assertContains('Leave request approved', $titles);
        $this->assertContains('Leave request rejected', $titles);
        $this->assertStringContainsString('Court hearing that week.', $employee->notifications()->get()->firstWhere('data.title', 'Leave request rejected')->data['body']);

        // The link: the list, for an employee who may not open the request;
        // the request itself for one who may.
        $link = fn (string $reason) => $employee->notifications()->get()->first(fn ($n) => str_contains($n->data['body'], $reason))->data['actions'][0]['url'];
        $this->assertSame(route('filament.mms.resources.leave-requests.index'), $link('Court hearing'));

        Gate::before(fn ($user, string $ability) => $user->is($employee) && $ability === 'Update:LeaveRequest' ? true : null);
        $again = $this->leaveRequest($employee);
        $service->reject($again, User::factory()->create(), 'Again.');
        $this->assertSame(route('filament.mms.resources.leave-requests.edit', $again), $link('Again.'));
    }

    public function test_a_user_alert_skips_nobody_and_never_throws(): void
    {
        UserAlert::send(null, 'Nothing');
        UserAlert::send([null], 'Nothing');

        $user = User::factory()->create();
        UserAlert::send([$user, $user], 'Once', 'Body', 'https://wakeel.test/x');

        $this->assertSame(['Once'], $this->titlesFor($user));
    }
}
