<?php

namespace Tests\Feature;

use App\Livewire\ChatWidget;
use App\Livewire\TodoNote;
use App\Models\User;
use App\Support\Dock;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The to-do note and the chat pinned to the page as a column: kept for the
 * user, open on every page while pinned, and the page told (the dock
 * store) — on wide screens it makes room and collapses the menu.
 */
class DockTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Gate::before(fn () => true);
        $this->user = User::factory()->create(['ui_preferences' => ['current_system' => 'mms']]);
        $this->actingAs($this->user);
    }

    public function test_each_is_pinned_and_unpinned_for_the_user_on_every_device(): void
    {
        $this->assertSame(['todo' => false, 'chat' => false], Dock::state($this->user));

        Livewire::test(TodoNote::class)->call('pin', true)->assertSet('isOpen', true);
        Livewire::test(ChatWidget::class, ['mode' => 'popup'])->call('pin', true)->assertSet('isOpen', true);

        $this->user->refresh();
        $this->assertSame(['todo' => true, 'chat' => true], Dock::state($this->user));
        // Their other preferences kept.
        $this->assertSame('mms', $this->user->ui_preferences['current_system']);

        Livewire::test(TodoNote::class)->call('pin', false);
        $this->assertSame(['todo' => false, 'chat' => true], Dock::state($this->user->refresh()));
    }

    public function test_pinned_they_are_open_on_every_page_whatever_was_left(): void
    {
        Dock::set($this->user, Dock::TODO, true);
        Dock::set($this->user, Dock::CHAT, true);
        session(['wakeel.todo.open' => false]);

        Livewire::test(TodoNote::class)->assertSet('isOpen', true);
        Livewire::test(ChatWidget::class, ['mode' => 'popup'])->assertSet('isOpen', true);
    }

    public function test_the_columns_width_is_dragged_and_kept_for_the_user(): void
    {
        $this->assertSame(Dock::WIDTH, Dock::width($this->user));

        Livewire::test(TodoNote::class)->call('saveDockWidth', 500);
        $this->assertSame(500, Dock::width($this->user->refresh()));

        // From the chat too; never too narrow or too wide.
        Livewire::test(ChatWidget::class, ['mode' => 'popup'])->call('saveDockWidth', 50);
        $this->assertSame(Dock::MIN_WIDTH, Dock::width($this->user->refresh()));
        Livewire::test(TodoNote::class)->call('saveDockWidth', 5000);
        $this->assertSame(Dock::MAX_WIDTH, Dock::width($this->user->refresh()));

        // The page starts at it.
        Filament::setCurrentPanel('mms');
        $this->get(Filament::getPanel('mms')->getUrl())->assertSee("'--wakeel-dock-w', '".Dock::MAX_WIDTH."px'", false);
    }

    public function test_the_page_knows_what_is_pinned(): void
    {
        Filament::setCurrentPanel('mms');
        Dock::set($this->user, Dock::TODO, true);

        $this->get(Filament::getPanel('mms')->getUrl())
            ->assertSuccessful()
            ->assertSee("Alpine.store('dock'", false)
            ->assertSee('const pinned = { todo: true, chat: false }', false);
    }
}
