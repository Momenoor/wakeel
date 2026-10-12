<?php

namespace Tests\Feature;

use App\Livewire\ChatWidget;
use App\Livewire\TodoNote;
use App\Models\ChatConversation;
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

    public function test_the_bubble_and_the_chat_page_check_on_one_interval(): void
    {
        $poll = 'wire:poll.'.LiveRefresh::SECONDS.'s.keep-alive="checkForNewMessages"';

        Livewire::test(ChatWidget::class, ['mode' => 'popup'])->assertSeeHtml($poll);
        Livewire::test(ChatWidget::class, ['mode' => 'page'])->assertSeeHtml($poll);
        // The to-do list does not check by itself.
        Livewire::test(TodoNote::class)->call('setOpen', true)->assertDontSeeHtml('wire:poll');
    }
}
