<?php

namespace Tests\Feature\Filament\Pages;

use App\Filament\Mms\Pages\Chat;
use App\Models\ChatConversation;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * The Chat nav page itself is just a thin shell embedding the ChatWidget
 * Livewire component full-screen — its own behavior (starting conversations,
 * sending messages, scoping) is covered by ChatWidgetTest.
 */
class ChatTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Chat has its own page permission; these users hold it.
        Gate::before(fn ($user, string $ability) => $ability === 'View:Chat' ? true : null);

        $this->actingAs(User::factory()->create());

        Filament::setCurrentPanel('admin');
    }

    public function test_any_authenticated_user_can_render_the_chat_page(): void
    {
        $this->get(Chat::getUrl())->assertSuccessful();
    }

    public function test_typing_on_a_phone_does_not_zoom_the_page(): void
    {
        // The iPhone zooms in on any field under 16px; on phone widths they're 16px.
        $this->get(Chat::getUrl())
            ->assertSuccessful()
            ->assertSee('@media (max-width: 767px) { input:not([type=checkbox])', false)
            ->assertSee('font-size: 16px !important', false);
    }

    public function test_a_conversation_has_the_emoji_picker_and_a_one_to_one_its_call_button(): void
    {
        $colleague = User::factory()->create();
        $conversation = ChatConversation::betweenUsers(auth()->user(), $colleague);

        $this->get(Chat::getUrl().'?conversation='.$conversation->id)
            ->assertSuccessful()
            // The emojis once in the page, the picker by the message box.
            ->assertSee("Alpine.data('wkEmoji'", false)
            ->assertSee('x-data="wkEmoji"', false)
            ->assertSee('🇦🇪', false)
            // Calls: the window that rings, and the phone icon of this conversation.
            ->assertSee('wkCall(', false)
            ->assertSee("\$dispatch('wk-call', { conversationId: {$conversation->id}", false);
    }

    public function test_guest_cannot_access_the_chat_page(): void
    {
        auth()->logout();

        $this->get(Chat::getUrl())->assertRedirect();
    }
}
