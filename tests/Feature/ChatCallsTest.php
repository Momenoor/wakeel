<?php

namespace Tests\Feature;

use App\Events\ChatCallSignal;
use App\Events\ChatMessageSent;
use App\Jobs\RingCallAgain;
use App\Models\ChatCall;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\PushSubscription;
use App\Models\User;
use App\Services\Chat\ChatCalls;
use App\Services\Push\WebPushSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Voice calls in the chat (App\Services\Chat\ChatCalls, ChatCallController): rung, answered, ended — and the line
 * each leaves in the conversation.
 */
class ChatCallsTest extends TestCase
{
    use RefreshDatabase;

    private User $caller;

    private User $callee;

    private ChatConversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        Gate::before(fn ($user, string $ability) => $ability === 'View:Chat' ? true : null);
        Event::fake([ChatCallSignal::class, ChatMessageSent::class]);

        $this->caller = User::factory()->create(['name' => 'Momen']);
        $this->callee = User::factory()->create(['name' => 'Sara']);
        $this->conversation = ChatConversation::betweenUsers($this->caller, $this->callee);
    }

    private function startCall(): ChatCall
    {
        $id = $this->actingAs($this->caller)
            ->postJson(route('chat.calls.store', $this->conversation), ['offer' => '{"type":"offer","sdp":"v=0"}'])
            ->assertOk()
            ->json('id');

        return ChatCall::findOrFail($id);
    }

    public function test_a_call_rings_for_the_other_one_of_the_conversation(): void
    {
        $call = $this->startCall();

        $this->assertSame(ChatCall::RINGING, $call->status);
        $this->assertSame($this->callee->id, (int) $call->callee_id);
        Event::assertDispatched(ChatCallSignal::class, fn (ChatCallSignal $e) => $e->payload['event'] === 'ring'
            && $e->payload['call_id'] === $call->id
            && $e->payload['name'] === 'Momen'
            && in_array($this->callee->id, $e->userIds, true));
    }

    public function test_the_one_called_reads_the_offer_answers_and_the_caller_reads_the_answer(): void
    {
        $call = $this->startCall();

        // The caller is not given the offer back; the one called is.
        $this->getJson(route('chat.calls.show', $call))->assertOk()->assertJsonPath('offer', null);
        $this->actingAs($this->callee)->getJson(route('chat.calls.show', $call))
            ->assertOk()->assertJsonPath('offer', '{"type":"offer","sdp":"v=0"}')->assertJsonPath('name', 'Momen');

        $this->postJson(route('chat.calls.answer', $call), ['answer' => '{"type":"answer","sdp":"v=0"}'])->assertOk();

        $this->assertSame(ChatCall::ANSWERED, $call->fresh()->status);
        Event::assertDispatched(ChatCallSignal::class, fn (ChatCallSignal $e) => $e->payload['event'] === 'answered');

        $this->actingAs($this->caller)->getJson(route('chat.calls.show', $call))
            ->assertOk()->assertJsonPath('answer', '{"type":"answer","sdp":"v=0"}');

        // Answered once only.
        $this->actingAs($this->callee)->postJson(route('chat.calls.answer', $call), ['answer' => 'x'])->assertStatus(409);
    }

    public function test_hanging_up_an_answered_call_leaves_its_length_in_the_conversation(): void
    {
        $call = $this->startCall();
        $this->actingAs($this->callee)->postJson(route('chat.calls.answer', $call), ['answer' => 'a'])->assertOk();

        $this->travel(65)->seconds();
        $this->postJson(route('chat.calls.end', $call), ['reason' => 'hangup'])->assertNoContent();

        $call->refresh();
        $this->assertSame(ChatCall::ENDED, $call->status);
        $this->assertSame('1:05', $call->durationLabel());

        $line = ChatMessage::where('chat_call_id', $call->id)->sole();
        $this->assertSame($this->conversation->id, (int) $line->chat_conversation_id);
        $this->assertSame($this->caller->id, (int) $line->user_id);
        $this->assertSame('📞 Voice call · 1:05', $line->preview());
        Event::assertDispatched(ChatCallSignal::class, fn (ChatCallSignal $e) => $e->payload['event'] === 'ended' && $e->payload['status'] === ChatCall::ENDED);

        // Hung up on both sides at once: one line only.
        $this->actingAs($this->caller)->postJson(route('chat.calls.end', $call), ['reason' => 'hangup'])->assertNoContent();
        $this->assertSame(1, ChatMessage::where('chat_call_id', $call->id)->count());
    }

    public function test_a_call_not_answered_is_missed_and_in_the_bell_of_the_one_called(): void
    {
        $call = $this->startCall();

        $this->postJson(route('chat.calls.end', $call), ['reason' => 'timeout'])->assertNoContent();

        $call->refresh();
        $this->assertSame(ChatCall::MISSED, $call->status);
        $this->assertSame('Missed voice call', $call->summary($this->callee->id));
        $this->assertSame('Voice call, no answer', $call->summary($this->caller->id));
        $this->assertSame(1, $this->callee->notifications()->count());
        $this->assertSame(0, $this->caller->notifications()->count());
    }

    public function test_the_one_called_declines(): void
    {
        $call = $this->startCall();

        $this->actingAs($this->callee)->postJson(route('chat.calls.end', $call), ['reason' => 'decline'])->assertNoContent();

        $this->assertSame(ChatCall::DECLINED, $call->fresh()->status);
        $this->assertSame('Voice call declined', $call->fresh()->summary($this->caller->id));
        // Declined by them: no bell for it.
        $this->assertSame(0, $this->callee->notifications()->count());
    }

    public function test_someone_on_a_call_is_busy(): void
    {
        $this->startCall();

        $third = User::factory()->create();
        $other = ChatConversation::betweenUsers($third, $this->callee);

        $this->actingAs($third)
            ->postJson(route('chat.calls.store', $other), ['offer' => 'o'])
            ->assertStatus(409)
            ->assertJsonPath('error', 'busy');
    }

    public function test_a_group_has_no_calls_and_others_cannot_reach_a_call(): void
    {
        $group = ChatConversation::group($this->caller, 'Team', [$this->callee->id]);

        $this->actingAs($this->caller)->postJson(route('chat.calls.store', $group), ['offer' => 'o'])->assertStatus(422);

        $call = $this->startCall();
        $outsider = User::factory()->create();

        $this->actingAs($outsider)->postJson(route('chat.calls.store', $this->conversation), ['offer' => 'o'])->assertForbidden();
        $this->getJson(route('chat.calls.show', $call))->assertForbidden();
        $this->postJson(route('chat.calls.end', $call), ['reason' => 'hangup'])->assertForbidden();
        $this->postJson(route('chat.calls.answer', $call), ['answer' => 'a'])->assertForbidden();
    }

    public function test_a_call_nobody_ended_is_closed_so_no_one_stays_busy(): void
    {
        $ringing = $this->startCall();

        $this->travel(ChatCall::RING_SECONDS + 20)->seconds();

        // The one called opening a page: nothing rings any more.
        $this->actingAs($this->callee)->getJson(route('chat.calls.current'))->assertOk()->assertJsonPath('call_id', null);
        $this->assertSame(ChatCall::MISSED, $ringing->fresh()->status);

        // An answered call whose browsers went quiet ends when they were last heard.
        $answered = $this->startCall();
        $this->actingAs($this->callee)->postJson(route('chat.calls.answer', $answered), ['answer' => 'a'])->assertOk();
        $this->travel(30)->seconds();
        $this->postJson(route('chat.calls.ping', $answered))->assertNoContent();
        $this->travel(ChatCall::SILENT_SECONDS + 5)->seconds();

        $this->getJson(route('chat.calls.current'))->assertOk();
        $answered->refresh();
        $this->assertSame(ChatCall::ENDED, $answered->status);
        $this->assertSame('0:30', $answered->durationLabel());
    }

    public function test_a_call_ringing_shows_on_a_page_opened_meanwhile(): void
    {
        $call = $this->startCall();

        $this->actingAs($this->callee)->getJson(route('chat.calls.current'))->assertOk()->assertJsonPath('call_id', $call->id);
    }

    public function test_a_call_rings_on_the_phones_of_the_one_called_and_goes_away_when_over(): void
    {
        $this->withoutDefer();
        config(['queue.default' => 'database']); // a worker: the re-rings wait their seconds
        Queue::fake();
        $pushed = [];
        $this->app->instance(WebPushSender::class, new class($pushed) extends WebPushSender
        {
            public function __construct(private array &$pushed) {}

            public function sendToUser(int $userId, array $payload): int
            {
                $this->pushed[] = [$userId, $payload];

                return 1;
            }
        });
        PushSubscription::create([
            'user_id' => $this->callee->id,
            'endpoint' => 'https://push.test/callee',
            'endpoint_hash' => PushSubscription::hashEndpoint('https://push.test/callee'),
            'public_key' => 'BKey',
            'auth_token' => 'auth',
        ]);

        $call = $this->startCall();

        // Rings on the locked phone: stays until touched, buzzes, opens the conversation.
        [$userId, $ring] = $pushed[0];
        $this->assertSame($this->callee->id, $userId);
        $this->assertSame('Momen', $ring['title']);
        $this->assertTrue($ring['requireInteraction']);
        $this->assertNotEmpty($ring['vibrate']);
        $this->assertSame('wakeel-chat-'.$this->conversation->id, $ring['tag']);
        $this->assertStringContainsString('conversation='.$this->conversation->id, $ring['url']);

        // Rings again twice while it still rings.
        Queue::assertPushed(RingCallAgain::class, 2);
        (new RingCallAgain($call->id))->handle(app(ChatCalls::class));
        $this->assertCount(2, $pushed);

        // Answered: taken off the phone — and not rung again.
        $this->actingAs($this->callee)->postJson(route('chat.calls.answer', $call), ['answer' => 'a'])->assertOk();
        $this->assertTrue(end($pushed)[1]['close']);
        $this->assertSame('wakeel-chat-'.$this->conversation->id, end($pushed)[1]['tag']);
        $count = count($pushed);
        (new RingCallAgain($call->id))->handle(app(ChatCalls::class));
        $this->assertCount($count, $pushed);
    }

    public function test_a_call_is_not_rung_again_when_jobs_run_at_once(): void
    {
        // Jobs run at once (no worker): a re-ring's delay is not kept, so it would ring with the first.
        $this->withoutDefer();
        config(['queue.default' => 'sync']);
        Queue::fake();

        $this->startCall();

        Queue::assertNotPushed(RingCallAgain::class);
    }

    public function test_the_turn_server_password_is_made_for_the_user(): void
    {
        config([
            'services.webrtc.stun' => 'stun:stun.example.com:19302',
            'services.webrtc.turn_urls' => 'turn:turn.example.com:3478?transport=udp,turn:turn.example.com:3478?transport=tcp',
            'services.webrtc.turn_secret' => 'shared-secret',
        ]);

        $servers = $this->actingAs($this->caller)->getJson(route('chat.calls.ice'))->assertOk()->json('iceServers');

        $this->assertSame(['stun:stun.example.com:19302'], $servers[0]['urls']);
        $this->assertCount(2, $servers[1]['urls']);
        [$expires, $userId] = explode(':', $servers[1]['username']);
        $this->assertSame((string) $this->caller->id, $userId);
        $this->assertGreaterThan(now()->getTimestamp(), (int) $expires);
        $this->assertSame(base64_encode(hash_hmac('sha1', $servers[1]['username'], 'shared-secret', true)), $servers[1]['credential']);

        // No TURN set up: STUN alone.
        config(['services.webrtc.turn_secret' => null]);
        $this->assertCount(1, $this->getJson(route('chat.calls.ice'))->json('iceServers'));
    }
}
