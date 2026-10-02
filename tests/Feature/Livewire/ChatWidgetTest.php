<?php

namespace Tests\Feature\Livewire;

use App\Events\ChatMessageSent;
use App\Events\ChatMessagesStatusChanged;
use App\Http\Middleware\TrackUserLastSeen;
use App\Livewire\ChatWidget;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class ChatWidgetTest extends TestCase
{
    use RefreshDatabase;

    private User $me;

    private User $colleague;

    protected function setUp(): void
    {
        parent::setUp();

        // Chat has its own page permission; these users hold it.
        Gate::before(fn ($user, string $ability) => $ability === 'View:Chat' ? true : null);

        $this->me = User::factory()->create();
        $this->colleague = User::factory()->create();
        $this->actingAs($this->me);
    }

    public function test_starting_a_conversation_creates_it_between_exactly_those_two_users(): void
    {
        Livewire::test(ChatWidget::class)
            ->call('startConversationWith', $this->colleague->id)
            ->assertSet('activeConversationId', fn ($id) => $id !== null);

        $conversation = ChatConversation::sole();

        $this->assertTrue($conversation->participants->pluck('id')->contains($this->me->id));
        $this->assertTrue($conversation->participants->pluck('id')->contains($this->colleague->id));
        $this->assertCount(2, $conversation->participants);
    }

    public function test_starting_a_conversation_twice_reuses_the_same_conversation(): void
    {
        $first = Livewire::test(ChatWidget::class)
            ->call('startConversationWith', $this->colleague->id)
            ->get('activeConversationId');

        $second = Livewire::test(ChatWidget::class)
            ->call('startConversationWith', $this->colleague->id)
            ->get('activeConversationId');

        $this->assertSame($first, $second);
        $this->assertSame(1, ChatConversation::count());
    }

    public function test_sending_a_message_persists_it_and_broadcasts_it(): void
    {
        // The broadcast runs after the response; run it straight away here.
        $this->withoutDefer();
        Event::fake([ChatMessageSent::class]);

        Livewire::test(ChatWidget::class)
            ->call('startConversationWith', $this->colleague->id)
            ->set('body', 'Hello there')
            ->call('sendMessage')
            ->assertSet('body', '');

        $message = ChatMessage::sole();

        $this->assertSame('Hello there', $message->body);
        $this->assertSame($this->me->id, $message->user_id);

        Event::assertDispatched(ChatMessageSent::class, fn ($event) => $event->message->id === $message->id);
    }

    public function test_the_form_sends_its_text_as_an_argument(): void
    {
        // What the thread's form does: it clears the input (and body)
        // before the request, passing the text itself.
        Livewire::test(ChatWidget::class)
            ->call('startConversationWith', $this->colleague->id)
            ->set('body', '')
            ->call('sendMessage', '  Sent at once  ');

        $this->assertSame('Sent at once', ChatMessage::sole()->body);
    }

    public function test_links_in_a_message_are_clickable_and_nothing_else_is_html(): void
    {
        $conversation = ChatConversation::betweenUsers($this->me, $this->colleague);
        ChatMessage::create([
            'chat_conversation_id' => $conversation->id,
            'user_id' => $this->colleague->id,
            'body' => "الرابط: https://teams.microsoft.com/l/meetup-join/19%3a?context=%7b%22Tid%22%7d.\nأو www.jpa.ae و info@jpa.ae <script>alert(1)</script>",
        ]);

        Livewire::test(ChatWidget::class)
            ->call('startConversationWith', $this->colleague->id)
            ->assertSeeHtml('<a href="https://teams.microsoft.com/l/meetup-join/19%3a?context=%7b%22Tid%22%7d" target="_blank" rel="noopener noreferrer"')
            ->assertSeeHtml('%7b%22Tid%22%7d</a>.')
            ->assertSeeHtml('<a href="https://www.jpa.ae"')
            ->assertSeeHtml('<a href="mailto:info@jpa.ae"')
            ->assertSeeHtml('&lt;script&gt;alert(1)&lt;/script&gt;')
            ->assertDontSeeHtml('<script>alert(1)</script>');
    }

    public function test_my_messages_show_sent_then_delivered_then_read(): void
    {
        $this->withoutDefer();
        Event::fake([ChatMessageSent::class, ChatMessagesStatusChanged::class]);

        $mine = Livewire::test(ChatWidget::class)
            ->call('startConversationWith', $this->colleague->id)
            ->call('sendMessage', 'هل وصل؟');
        $message = ChatMessage::sole();

        // Not yet on their screen: one tick.
        $mine->call('$refresh')->assertSeeHtml('data-status="sent"');

        // Their Wakeel open, the chat on another conversation: delivered.
        $this->actingAs($this->colleague);
        Livewire::test(ChatWidget::class, ['mode' => 'popup']);
        $this->assertNotNull($message->fresh()->delivered_at);
        Event::assertDispatched(ChatMessagesStatusChanged::class, fn ($e) => $e->userIds === [$this->me->id]);

        $this->actingAs($this->me);
        $mine->call('$refresh')->assertSeeHtml('data-status="delivered"');

        // They open the conversation: read.
        $this->travel(1)->minutes();
        $this->actingAs($this->colleague);
        Livewire::test(ChatWidget::class)->call('selectConversation', $message->chat_conversation_id);
        Event::assertDispatchedTimes(ChatMessagesStatusChanged::class, 2);

        $this->actingAs($this->me);
        $mine->call('$refresh')->assertSeeHtml('data-status="read"');

        // Their own messages carry no ticks.
        $this->assertSame(1, substr_count($mine->html(), 'data-status='));
    }

    public function test_a_message_can_answer_one_of_the_conversation(): void
    {
        $this->withoutDefer();
        Event::fake([ChatMessageSent::class, ChatMessagesStatusChanged::class]);

        $conversation = ChatConversation::betweenUsers($this->me, $this->colleague);
        $question = ChatMessage::create(['chat_conversation_id' => $conversation->id, 'user_id' => $this->colleague->id, 'body' => 'متى الاجتماع؟']);
        $elsewhere = ChatMessage::create([
            'chat_conversation_id' => ChatConversation::betweenUsers($this->colleague, User::factory()->create())->id,
            'user_id' => $this->colleague->id, 'body' => 'سر',
        ]);

        $chat = Livewire::test(ChatWidget::class)
            ->call('selectConversation', $conversation->id)
            // Not of this conversation: nothing to answer.
            ->call('replyTo', $elsewhere->id)
            ->assertSet('replyToId', null)
            ->call('replyTo', $question->id)
            ->assertSet('replyToId', $question->id)
            ->assertSee('متى الاجتماع؟')
            ->call('sendMessage', 'الساعة العاشرة')
            ->assertSet('replyToId', null);

        $answer = ChatMessage::latest('id')->first();
        $this->assertSame($question->id, $answer->reply_to_id);

        // Quoted above the answer, a tap away from the question.
        $chat->assertSeeHtml("getElementById('chat-msg-{$question->id}')")
            ->assertSeeHtml('id="chat-msg-'.$question->id.'"');
    }

    public function test_files_are_sent_with_a_message_and_open_only_for_the_conversation(): void
    {
        $this->withoutDefer();
        Event::fake([ChatMessageSent::class, ChatMessagesStatusChanged::class]);
        Storage::fake(ChatMessage::DISK);

        $chat = Livewire::test(ChatWidget::class)
            ->call('startConversationWith', $this->colleague->id)
            ->set('uploads', [UploadedFile::fake()->image('photo.jpg'), UploadedFile::fake()->create('report.pdf', 100, 'application/pdf')])
            ->call('sendMessage', '')
            ->assertHasNoErrors()
            ->assertSet('uploads', []);

        $message = ChatMessage::sole();
        $this->assertSame(['photo.jpg', 'report.pdf'], array_column($message->files(), 'name'));
        $this->assertSame('📎 photo.jpg +1', $message->preview());
        Storage::disk(ChatMessage::DISK)->assertExists($message->files()[1]['path']);

        // The picture shown, the PDF to download.
        $chat->assertSeeHtml('src="'.route('chat.attachment', [$message, 0]).'"')
            ->assertSeeHtml('href="'.route('chat.attachment', [$message, 1]).'?download=1"');

        $this->get(route('chat.attachment', [$message, 1]).'?download=1')->assertOk()->assertDownload('report.pdf');
        $this->actingAs($this->colleague)->get(route('chat.attachment', [$message, 0]))->assertOk();
        $this->actingAs(User::factory()->create())->get(route('chat.attachment', [$message, 0]))->assertForbidden();

        // Up to the limit: taken (Livewire's own upload limit allows it).
        $this->actingAs($this->me);
        Livewire::test(ChatWidget::class)
            ->call('startConversationWith', $this->colleague->id)
            ->set('uploads', [UploadedFile::fake()->create('big.zip', ChatWidget::MAX_KB)])
            ->assertHasNoErrors()
            ->call('sendMessage', '')
            ->assertHasNoErrors();
        $this->assertSame('big.zip', ChatMessage::latest('id')->first()->files()[0]['name']);

        // Too big: refused as it's picked, and nothing goes.
        Livewire::test(ChatWidget::class)
            ->call('startConversationWith', $this->colleague->id)
            ->set('uploads', [UploadedFile::fake()->create('huge.zip', ChatWidget::MAX_KB + 1)])
            ->assertHasErrors('uploads.0')
            ->assertSet('uploads', [])
            ->call('sendMessage', '');
        $this->assertSame(2, ChatMessage::count());
    }

    public function test_the_broadcast_goes_to_every_participants_personal_channel(): void
    {
        $conversation = ChatConversation::betweenUsers($this->me, $this->colleague);
        $message = ChatMessage::create([
            'chat_conversation_id' => $conversation->id,
            'user_id' => $this->me->id,
            'body' => 'hi',
        ]);

        $channels = collect((new ChatMessageSent($message->load('sender')))->broadcastOn())
            ->map(fn (PrivateChannel $channel) => (string) $channel)
            ->all();

        $this->assertContains('private-App.Models.User.'.$this->me->id, $channels);
        $this->assertContains('private-App.Models.User.'.$this->colleague->id, $channels);
    }

    public function test_a_blank_message_is_not_sent(): void
    {
        Livewire::test(ChatWidget::class)
            ->call('startConversationWith', $this->colleague->id)
            ->set('body', '   ')
            ->call('sendMessage');

        $this->assertSame(0, ChatMessage::count());
    }

    public function test_a_user_cannot_read_a_conversation_they_are_not_part_of(): void
    {
        $stranger = User::factory()->create();
        $conversation = ChatConversation::betweenUsers($this->colleague, $stranger);
        ChatMessage::create([
            'chat_conversation_id' => $conversation->id,
            'user_id' => $this->colleague->id,
            'body' => 'private to the other two',
        ]);

        $component = Livewire::test(ChatWidget::class)
            ->call('selectConversation', $conversation->id);

        // Selecting an id doesn't authorize it — activeConversation and
        // messages are always derived from the current user's own
        // conversations relationship, so a conversation you don't belong to
        // resolves to null/empty rather than leaking its content.
        $this->assertNull($component->instance()->activeConversation);
        $this->assertCount(0, $component->instance()->messages);
    }

    public function test_toggle_open_flips_the_popup_state(): void
    {
        Livewire::test(ChatWidget::class, ['mode' => 'popup'])
            ->assertSet('isOpen', false)
            ->call('toggleOpen')
            ->assertSet('isOpen', true)
            ->call('toggleOpen')
            ->assertSet('isOpen', false);
    }

    public function test_back_to_list_clears_the_active_conversation(): void
    {
        Livewire::test(ChatWidget::class)
            ->call('startConversationWith', $this->colleague->id)
            ->assertSet('activeConversationId', fn ($id) => $id !== null)
            ->call('backToList')
            ->assertSet('activeConversationId', null);
    }

    public function test_a_user_online_within_the_last_minute_is_reported_online(): void
    {
        $this->colleague->forceFill(['last_seen_at' => now()->subSeconds(10)])->save();
        $this->assertTrue($this->colleague->isOnline());

        $this->colleague->forceFill(['last_seen_at' => now()->subMinutes(5)])->save();
        $this->assertFalse($this->colleague->fresh()->isOnline());
    }

    public function test_every_render_refreshes_who_is_online_for_the_status_dots(): void
    {
        $this->colleague->forceFill(['last_seen_at' => now()->subMinutes(5)])->save();

        $widget = Livewire::test(ChatWidget::class)
            ->assertSet('onlineUserIds', fn ($ids) => ! in_array($this->colleague->id, $ids));

        $this->colleague->forceFill(['last_seen_at' => now()])->save();

        $widget->call('$refresh')
            ->assertSet('onlineUserIds', fn ($ids) => in_array($this->colleague->id, $ids));
    }

    public function test_a_closed_popup_does_not_render_its_hidden_content(): void
    {
        // The popup panel's header (chat-body) — only there once opened. The
        // header text itself, not the bare word: the poll attribute
        // (wire:poll="checkForNewMessages") contains "Messages" too.
        $header = '>'.__('Messages').'</span>';

        Livewire::test(ChatWidget::class, ['mode' => 'popup'])
            ->assertDontSeeHtml($header)
            ->call('toggleOpen')
            ->assertSeeHtml($header);
    }

    public function test_the_panels_stamp_last_seen_on_their_polling_requests_too(): void
    {
        // Registered with Livewire once a panel boots.
        Filament::setCurrentPanel('pms');
        Filament::getPanel('pms')->bootUsing(fn () => null)->boot();

        $this->assertContains(TrackUserLastSeen::class, Livewire::getPersistentMiddleware());
    }
}
