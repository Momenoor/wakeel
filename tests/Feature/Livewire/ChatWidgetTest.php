<?php

namespace Tests\Feature\Livewire;

use App\Events\ChatMessageSent;
use App\Events\ChatMessagesStatusChanged;
use App\Http\Middleware\TrackUserLastSeen;
use App\Livewire\ChatWidget;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\User;
use App\Services\Chat\ChatMessenger;
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

        $conversation = ChatConversation::betweenUsers($this->me, $this->colleague);
        $question = ChatMessage::create(['chat_conversation_id' => $conversation->id, 'user_id' => $this->colleague->id, 'body' => 'أرسل المستندات']);
        $send = fn (array $data) => $this->postJson(route('chat.messages.store', $conversation), $data);

        // Straight from the browser: the files with the text, as an answer.
        $send(['body' => 'تفضل', 'reply_to_id' => $question->id, 'files' => [UploadedFile::fake()->image('photo.jpg'), UploadedFile::fake()->create('report.pdf', 100, 'application/pdf')]])
            ->assertOk();

        $message = ChatMessage::latest('id')->first();
        $this->assertSame('تفضل', $message->body);
        $this->assertSame($question->id, $message->reply_to_id);
        $this->assertSame(['photo.jpg', 'report.pdf'], array_column($message->files(), 'name'));
        Storage::disk(ChatMessage::DISK)->assertExists($message->files()[1]['path']);
        Event::assertDispatched(ChatMessageSent::class);

        // Files alone.
        $send(['files' => [UploadedFile::fake()->image('only.png')]])->assertOk();
        $this->assertSame('📎 only.png', ChatMessage::latest('id')->first()->preview());

        // The picture shown, the PDF to download; the files go up to the
        // conversation's own address.
        Livewire::test(ChatWidget::class)->call('selectConversation', $conversation->id)
            ->assertSeeHtml('src="'.route('chat.attachment', [$message, 0]).'"')
            ->assertSeeHtml('href="'.route('chat.attachment', [$message, 1]).'?download=1"')
            ->assertSeeHtml(str_replace('/', '\/', route('chat.messages.store', $conversation)));

        $this->get(route('chat.attachment', [$message, 1]).'?download=1')->assertOk()->assertDownload('report.pdf');
        $this->actingAs($this->colleague)->get(route('chat.attachment', [$message, 0]))->assertOk();
        $this->actingAs(User::factory()->create())->get(route('chat.attachment', [$message, 0]))->assertForbidden();

        // Not in the conversation: nothing sent.
        $this->postJson(route('chat.messages.store', $conversation), ['files' => [UploadedFile::fake()->image('x.png')]])->assertForbidden();

        // Up to the limit: taken; too big, or too many: refused, nothing sent.
        $this->actingAs($this->me);
        $send(['files' => [UploadedFile::fake()->create('big.zip', ChatMessenger::MAX_KB)]])->assertOk();
        $count = ChatMessage::count();
        $send(['body' => 'كبير', 'files' => [UploadedFile::fake()->create('huge.zip', ChatMessenger::MAX_KB + 1)]])->assertJsonValidationErrors('files.0');
        $send(['files' => array_map(fn ($i) => UploadedFile::fake()->image("p{$i}.png"), range(1, ChatMessenger::MAX_FILES + 1))])->assertJsonValidationErrors('files');
        $this->assertSame($count, ChatMessage::count());
    }

    public function test_the_ticks_broadcast_redraws_the_chat(): void
    {
        // As Echo hands it over: the event with its data.
        Livewire::test(ChatWidget::class)
            ->call('startConversationWith', $this->colleague->id)
            ->call('__dispatch', 'echo-private:App.Models.User.'.$this->me->id.',.chat.status', [['conversation_id' => 1]])
            ->assertOk();
    }

    public function test_a_voice_note_is_sent_and_played_in_the_chat(): void
    {
        $this->withoutDefer();
        Event::fake([ChatMessageSent::class, ChatMessagesStatusChanged::class]);
        Storage::fake(ChatMessage::DISK);
        $conversation = ChatConversation::betweenUsers($this->me, $this->colleague);

        // As the browser records it: a WebM file, marked as a voice note.
        $this->postJson(route('chat.messages.store', $conversation), [
            'voice' => '1',
            'files' => [UploadedFile::fake()->createWithContent('voice-note-2026-10-03.webm', str_repeat("\x1A\x45\xDF\xA3", 64))],
        ])->assertOk();
        // And a video.
        $this->postJson(route('chat.messages.store', $conversation), ['files' => [UploadedFile::fake()->create('clip.mp4', 10, 'video/mp4')]])->assertOk();

        [$voice, $video] = ChatMessage::orderBy('id')->get();
        $this->assertTrue($voice->files()[0]['voice']);
        $this->assertTrue(ChatMessage::isAudio($voice->files()[0]));
        $this->assertSame('🎤 Voice note', $voice->preview());

        // Played in the bubble — the voice note as sound, the video as video.
        Livewire::test(ChatWidget::class)->call('selectConversation', $conversation->id)
            ->assertSeeHtml('<audio controls preload="metadata" src="'.route('chat.attachment', [$voice, 0]).'"')
            ->assertSeeHtml('<video controls preload="metadata" src="'.route('chat.attachment', [$video, 0]).'"');

        // Sent as a file the player can seek in.
        $this->get(route('chat.attachment', [$voice, 0]))->assertOk()->assertHeader('Accept-Ranges', 'bytes');
        $this->get(route('chat.attachment', [$video, 0]).'?download=1')->assertDownload('clip.mp4');
    }

    public function test_a_group_is_made_talked_in_and_managed(): void
    {
        $this->withoutDefer();
        Event::fake([ChatMessageSent::class, ChatMessagesStatusChanged::class]);
        $third = User::factory()->create(['name' => 'سارة']);
        $fourth = User::factory()->create(['name' => 'خالد']);

        // Two others at least, and a name.
        $chat = Livewire::test(ChatWidget::class)
            ->call('startGroup')
            ->set('groupName', '')
            ->set('groupMembers', [$this->colleague->id])
            ->call('createGroup')
            ->assertHasErrors(['groupName', 'groupMembers'])
            ->set('groupName', 'فريق الخبرة')
            ->set('groupMembers', [$this->colleague->id, $third->id])
            ->call('createGroup')
            ->assertHasNoErrors();

        $group = ChatConversation::where('is_group', true)->sole();
        $this->assertSame('فريق الخبرة', $group->name);
        $this->assertEqualsCanonicalizing([$this->me->id, $this->colleague->id, $third->id], $group->participants->pluck('id')->all());
        $chat->assertSet('activeConversationId', $group->id)->assertSee('فريق الخبرة')->assertSee('3 members');

        // Its messages reach everyone in it; others' show who wrote them.
        $chat->call('sendMessage', 'صباح الخير');
        ChatMessage::create(['chat_conversation_id' => $group->id, 'user_id' => $third->id, 'body' => 'أهلاً']);
        $chat->call('$refresh')->assertSeeHtml('color: rgb(124 58 237);">سارة</p>');
        $this->assertCount(3, (new ChatMessageSent(ChatMessage::first()->load('sender')))->broadcastOn());

        // A group of two people isn't their one-to-one conversation.
        $this->assertNotSame($group->id, ChatConversation::betweenUsers($this->me, $this->colleague)->id);

        // Members: renamed, added; removed only by whoever made the group.
        $chat->call('toggleMembers')
            ->call('renameGroup', 'فريق الخبرة الحسابية')
            ->set('addingMembers', [$fourth->id])
            ->call('addMembers');
        $this->assertSame('فريق الخبرة الحسابية', $group->fresh()->name);
        $this->assertTrue($group->participants()->whereKey($fourth->id)->exists());

        $this->actingAs($this->colleague);
        Livewire::test(ChatWidget::class)->call('selectConversation', $group->id)->call('removeMember', $third->id);
        $this->assertTrue($group->participants()->whereKey($third->id)->exists());

        $this->actingAs($this->me);
        Livewire::test(ChatWidget::class)->call('selectConversation', $group->id)->call('removeMember', $third->id);
        $this->assertFalse($group->participants()->whereKey($third->id)->exists());

        // Left: no longer theirs to see.
        $this->actingAs($this->colleague);
        Livewire::test(ChatWidget::class)->call('selectConversation', $group->id)->call('leaveGroup')->assertSet('activeConversationId', null);
        $this->assertFalse($group->participants()->whereKey($this->colleague->id)->exists());
        Livewire::test(ChatWidget::class)->call('selectConversation', $group->id)->assertDontSee('صباح الخير');
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
