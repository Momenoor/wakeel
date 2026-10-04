<?php

namespace Tests\Feature;

use App\Livewire\ChatWidget;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The floating chat opens itself on a new message, on the conversation
 * with the newest one.
 */
class ChatPopupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Chat has its own page permission; these users hold it.
        Gate::before(fn ($user, string $ability) => $ability === 'View:Chat' ? true : null);
    }

    private function message(ChatConversation $conversation, User $from, string $body): ChatMessage
    {
        $message = ChatMessage::create(['chat_conversation_id' => $conversation->id, 'user_id' => $from->id, 'body' => $body]);

        // As ChatWidget::sendMessage() does.
        $conversation->update(['last_message_at' => $message->created_at]);

        return $message;
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

    public function test_the_back_arrow_dot_is_only_for_other_conversations(): void
    {
        $me = User::factory()->create();
        $ali = User::factory()->create();
        $sara = User::factory()->create();
        $withAli = ChatConversation::betweenUsers($me, $ali);
        $withSara = ChatConversation::betweenUsers($me, $sara);

        $this->actingAs($me);
        $widget = Livewire::test(ChatWidget::class, ['mode' => 'popup'])
            ->call('toggleOpen')
            ->call('selectConversation', $withAli->id);

        // A message in the conversation being read: read, no dot.
        $this->travel(1)->seconds();
        $this->message($withAli, $ali, 'in this one');
        $widget->call('onMessageReceived')
            ->assertSee('in this one')
            ->assertSet('unreadCount', 0)
            ->assertSet('unreadElsewhereCount', 0);

        // One from someone else: the dot shows.
        $this->travel(1)->seconds();
        $this->message($withSara, $sara, 'elsewhere');
        $widget->call('onMessageReceived')
            ->assertSet('activeConversationId', $withAli->id)
            ->assertSet('unreadElsewhereCount', 1);
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

    public function test_pages_load_the_chat_in_the_background_not_with_the_page(): void
    {
        Gate::before(fn () => true);
        $me = User::factory()->create();
        $conversation = ChatConversation::betweenUsers($me, User::factory()->create());
        $this->message($conversation, $me, 'Not on the page itself');
        $this->actingAs($me);
        session(['chat.popup' => ['open' => true, 'conversation' => $conversation->id]]);

        // The page carries only a bubble standing in, and the instruction to
        // load the chat after it — not the conversations nor the open one.
        $this->get(route('filament.mms.pages.user-guide'))
            ->assertSuccessful()
            ->assertSee('x-intersect="$wire.__lazyLoad', false)
            ->assertDontSee('Not on the page itself');
    }

    public function test_a_background_check_with_nothing_new_sends_nothing_back(): void
    {
        $me = User::factory()->create();
        $conversation = ChatConversation::betweenUsers($me, User::factory()->create());
        $this->actingAs($me);

        $popup = Livewire::test(ChatWidget::class, ['mode' => 'popup'])
            ->call('setOpen', true)
            ->call('selectConversation', $conversation->id)
            ->call('checkForNewMessages');

        $this->assertArrayNotHasKey('html', $popup->effects);
    }

    public function test_every_loading_indicator_is_hidden_until_something_loads(): void
    {
        // Livewire hides wire:loading elements before they are used with one
        // style per exact spelling; any other spelling stays on screen for
        // good — the chat's "loading" overlay did, after loading was done.
        preg_match_all('/\[wire\\\\:(loading[^\]]*)\]/', \Livewire\Mechanisms\FrontendAssets\FrontendAssets::styles(), $hidden);
        $hidden = array_unique(str_replace('\\', '', $hidden[1]));

        $wrong = [];
        foreach (\Symfony\Component\Finder\Finder::create()->files()->in(resource_path('views'))->name('*.blade.php') as $file) {
            preg_match_all('/wire:(loading(?:\.[a-z-]+)*)(?=[\s>=])/', $file->getContents(), $found);
            foreach (array_unique($found[1]) as $spelling) {
                if (! in_array($spelling, $hidden, true) && ! str_starts_with($spelling, 'loading.remove') && ! str_starts_with($spelling, 'loading.attr') && ! str_starts_with($spelling, 'loading.class')) {
                    $wrong[] = $file->getRelativePathname().': wire:'.$spelling;
                }
            }
        }

        $this->assertSame([], $wrong);
    }
}
