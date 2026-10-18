<?php

namespace Tests\Feature;

use App\Events\RecordsChanged;
use App\Filament\Mms\Widgets\MatterStatsWidget;
use App\Livewire\ChatWidget;
use App\Livewire\NotificationPoller;
use App\Models\Matter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * With Pusher set up, the pages are told what changed instead of polling for it; without it, they
 * poll as before.
 */
class LiveViaPusherTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Gate::before(fn () => true);
        $this->actingAs(User::factory()->create());
    }

    private function withPusher(bool $on = true): void
    {
        config(['filament.broadcasting.echo' => $on ? ['broadcaster' => 'pusher', 'key' => 'k'] : null]);
    }

    public function test_a_change_is_announced_over_pusher_at_most_once_every_few_seconds(): void
    {
        Event::fake([RecordsChanged::class]);

        // Without Pusher: nothing to tell.
        $this->withPusher(false);
        Matter::factory()->create();
        Event::assertNotDispatched(RecordsChanged::class);

        // With it: told — once for a burst of changes.
        $this->withPusher();
        Cache::flush();
        Matter::factory()->count(3)->create();
        Event::assertDispatchedTimes(RecordsChanged::class, 1);
        Event::assertDispatched(RecordsChanged::class, fn (RecordsChanged $event) => $event->broadcastOn()->name === 'private-records' && $event->broadcastAs() === 'records.changed');
    }

    public function test_dashboard_widgets_are_told_not_polled_with_pusher(): void
    {
        $this->withPusher(false);
        $widget = Livewire::test(MatterStatsWidget::class)->instance();
        $this->assertSame('120s', (fn () => $this->getPollingInterval())->call($widget));

        $this->withPusher();
        $widget = Livewire::test(MatterStatsWidget::class)->instance();
        $this->assertNull((fn () => $this->getPollingInterval())->call($widget));
        $listeners = (fn () => $this->getListeners())->call($widget);
        $this->assertSame('$refresh', $listeners['echo-private:records,.records.changed']);
        $this->assertSame('$refresh', $listeners['wakeel-reconnected']);
    }

    public function test_the_chat_and_the_notifications_do_not_poll_with_pusher_and_catch_up_when_it_is_back(): void
    {
        $this->withPusher(false);
        Livewire::test(ChatWidget::class, ['mode' => 'page'])->assertSeeHtml('="checkForNewMessages"');
        Livewire::test(NotificationPoller::class)->assertSeeHtml('wire:poll.10s="checkNotifications"');

        $this->withPusher();
        $chat = Livewire::test(ChatWidget::class, ['mode' => 'page'])->assertDontSeeHtml('="checkForNewMessages"');
        $this->assertSame('checkForNewMessages', (fn () => $this->getListeners())->call($chat->instance())['wakeel-reconnected']);
        $poller = Livewire::test(NotificationPoller::class)->assertDontSeeHtml('wire:poll');
        $this->assertSame('checkNotifications', (fn () => $this->getListeners())->call($poller->instance())['wakeel-reconnected']);
    }
}
