<?php

namespace Tests\Feature;

use App\Livewire\ChatWidget;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The floating chat opens itself on a new message, on the conversation
 * with the newest one.
 */
class ChatPopupTest extends TestCase
{
    use RefreshDatabase;

    private function message(ChatConversation $conversation, User $from, string $body): ChatMessage
    {
        return ChatMessage::create(['chat_conversation_id' => $conversation->id, 'user_id' => $from->id, 'body' => $body]);
    }

    public function test_a_new_message_opens_the_popup_on_the_newest_conversation(): void
    {
        $me = User::factory()->create();
        $ali = User::factory()->create();
        $sara = User::factory()->create();
        $withAli = ChatConversation::betweenUsers($me, $ali);
        $withSara = ChatConversation::betweenUsers($me, $sara);

        // Already there before the page loaded: not a new message.
        $this->message($withAli, $ali, 'old');

        $this->actingAs($me);
        $widget = Livewire::test(ChatWidget::class, ['mode' => 'popup'])
            ->call('checkForNewMessages')
            ->assertSet('isOpen', false);

        // Two arrive; the newest is Sara's.
        $this->message($withAli, $ali, 'hello');
        $this->message($withSara, $sara, 'hi');

        $widget->call('onMessageReceived')
            ->assertSet('isOpen', true)
            ->assertSet('activeConversationId', $withSara->id)
            ->assertSee('hi');

        $this->assertFalse($widget->instance()->isConversationUnread($withSara->fresh('participants')));

        // Another from Ali while Sara's is open: stays on Sara's.
        $this->message($withAli, $ali, 'are you there?');
        $widget->call('checkForNewMessages')
            ->assertSet('isOpen', true)
            ->assertSet('activeConversationId', $withSara->id);

        // Back on the list, the next one opens its conversation.
        $widget->call('backToList');
        $this->message($withAli, $ali, 'hello again');
        $widget->call('checkForNewMessages')->assertSet('activeConversationId', $withAli->id);

        // My own messages never pop it.
        $widget->set('isOpen', false);
        $this->message($withAli, $me, 'yes');
        $widget->call('checkForNewMessages')->assertSet('isOpen', false);
    }

    public function test_the_popup_stays_as_it_was_left_on_the_next_page(): void
    {
        $me = User::factory()->create();
        $ali = User::factory()->create();
        $conversation = ChatConversation::betweenUsers($me, $ali);

        $this->actingAs($me);
        Livewire::test(ChatWidget::class, ['mode' => 'popup'])
            ->call('toggleOpen')
            ->call('selectConversation', $conversation->id);

        // A refresh or another page: a fresh component, same state.
        Livewire::test(ChatWidget::class, ['mode' => 'popup'])
            ->assertSet('isOpen', true)
            ->assertSet('activeConversationId', $conversation->id)
            ->call('toggleOpen');

        Livewire::test(ChatWidget::class, ['mode' => 'popup'])->assertSet('isOpen', false);

        // The full Chat page never takes the popup's state.
        Livewire::test(ChatWidget::class, ['mode' => 'page'])->assertSet('activeConversationId', null);
    }

    public function test_the_arrows_point_the_right_way_in_arabic(): void
    {
        $me = User::factory()->create();
        $conversation = ChatConversation::betweenUsers($me, User::factory()->create());
        $this->actingAs($me);

        app()->setLocale('ar');
        Livewire::test(ChatWidget::class, ['mode' => 'popup'])
            ->call('toggleOpen')
            ->call('selectConversation', $conversation->id)
            ->assertSeeHtml('style="transform: scaleX(-1)"');

        app()->setLocale('en');
        Livewire::test(ChatWidget::class, ['mode' => 'popup'])
            ->assertSet('isOpen', true)
            ->assertDontSeeHtml('scaleX(-1)');
    }

    public function test_the_full_chat_page_does_not_pop(): void
    {
        $me = User::factory()->create();
        $ali = User::factory()->create();
        $conversation = ChatConversation::betweenUsers($me, $ali);

        $this->actingAs($me);
        $page = Livewire::test(ChatWidget::class, ['mode' => 'page']);

        $this->message($conversation, $ali, 'hello');

        $page->call('checkForNewMessages')
            ->assertSet('isOpen', false)
            ->assertSet('activeConversationId', null);
    }
}
