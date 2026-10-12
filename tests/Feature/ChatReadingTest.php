<?php

namespace Tests\Feature;

use App\Livewire\ChatWidget;
use App\Livewire\TodoNote;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\Setting;
use App\Models\User;
use App\Services\Chat\ChatMessenger;
use App\Services\Chat\ChatReading;
use App\Support\LiveRefresh;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A conversation on screen — the Chat page, or the bubble open on it — is
 * being read: its new messages bring no bell, push or desktop notice. The
 * bubble and the Chat page check for messages on one shared interval.
 */
class ChatReadingTest extends TestCase
{
    use RefreshDatabase;

    private User $me;

    private User $ali;

    private ChatConversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        Gate::before(fn ($user, string $ability) => $ability === 'View:Chat' ? true : null);
        $this->me = User::factory()->create();
        $this->ali = User::factory()->create();
        $this->conversation = ChatConversation::betweenUsers($this->me, $this->ali);
        $this->actingAs($this->me);
    }

    private function aliWrites(string $body): void
    {
        app(ChatMessenger::class)->send($this->conversation->fresh('participants'), $this->ali, $body);
    }

    public function test_a_conversation_on_screen_gets_no_notifications_and_one_left_does(): void
    {
        // The Chat page, on the conversation.
        $page = Livewire::test(ChatWidget::class, ['mode' => 'page', 'conversation' => $this->conversation->id]);
        $this->assertTrue(ChatReading::isReading($this->me->id, $this->conversation->id));

        $this->aliWrites('are you there?');
        $this->assertSame(0, $this->me->notifications()->count());
        // Nor the desktop notice, as the page checks.
        $page->call('checkForNewMessages')->assertNotDispatched('wakeel-desktop-notification');

        // Back to the list: off screen, notified again.
        $page->call('backToList');
        $this->assertFalse(ChatReading::isReading($this->me->id, $this->conversation->id));
        $this->aliWrites('hello?');
        $this->assertSame(1, $this->me->notifications()->count());
    }

    public function test_the_bubble_reads_only_while_open_on_the_conversation(): void
    {
        $bubble = Livewire::test(ChatWidget::class, ['mode' => 'popup'])
            ->call('setOpen', true)
            ->call('selectConversation', $this->conversation->id);
        $this->assertTrue(ChatReading::isReading($this->me->id, $this->conversation->id));

        // Minimised: notified.
        $bubble->call('setOpen', false);
        $this->assertFalse(ChatReading::isReading($this->me->id, $this->conversation->id));
        $this->aliWrites('ping');
        $this->assertSame(1, $this->me->notifications()->count());
    }

    public function test_a_voice_note_is_played_with_its_waveform_length_and_speed(): void
    {
        ChatMessage::create(['chat_conversation_id' => $this->conversation->id, 'user_id' => $this->ali->id, 'body' => '',
            'attachments' => [['path' => 'chat/1/voice.webm', 'name' => 'voice-note.webm', 'mime' => 'audio/webm', 'size' => 1000, 'voice' => true]]]);

        Livewire::test(ChatWidget::class, ['mode' => 'page', 'conversation' => $this->conversation->id])
            ->assertSeeHtml('x-data="wkVoice(')
            ->assertDontSeeHtml('<audio controls');
    }

    public function test_the_bubble_and_the_chat_page_check_on_one_interval(): void
    {
        // The interval set in System Settings, within its bounds.
        $this->assertSame(LiveRefresh::DEFAULT, LiveRefresh::seconds());
        Setting::set(LiveRefresh::SETTING, 3, 'notifications');
        $this->assertSame(3, LiveRefresh::seconds());
        Setting::set(LiveRefresh::SETTING, 0, 'notifications');
        $this->assertSame(LiveRefresh::DEFAULT, LiveRefresh::seconds());
        Setting::set(LiveRefresh::SETTING, 500, 'notifications');
        $this->assertSame(LiveRefresh::MAX, LiveRefresh::seconds());
        Setting::set(LiveRefresh::SETTING, 3, 'notifications');

        // The Chat page keeps checking in a background tab; the bubble pauses there.
        Livewire::test(ChatWidget::class, ['mode' => 'page'])->assertSeeHtml('wire:poll.3s.keep-alive="checkForNewMessages"');
        Livewire::test(ChatWidget::class, ['mode' => 'popup'])->assertSeeHtml('wire:poll.3s="checkForNewMessages"');
        // The to-do list does not check by itself.
        Livewire::test(TodoNote::class)->call('setOpen', true)->assertDontSeeHtml('wire:poll');
    }
}
