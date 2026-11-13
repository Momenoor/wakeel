<?php

namespace Tests\Feature;

use App\Events\RecordsChanged;
use App\Filament\Mms\Resources\Matters\MatterResource;
use App\Filament\Mms\Resources\Matters\Pages\ListMatters;
use App\Filament\Mms\Widgets\MatterStatsWidget;
use App\Filament\Mms\Widgets\UpcomingSessionsWidget;
use App\Filament\Mms\Widgets\VacationCalendarWidget;
use App\Livewire\ChatWidget;
use App\Livewire\NotificationPoller;
use App\Models\Matter;
use App\Models\User;
use App\Support\Live;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\CallQueuedClosure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
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

    public function test_a_change_too_soon_after_the_last_is_announced_once_the_wait_is_over_where_a_worker_runs(): void
    {
        Event::fake([RecordsChanged::class]);
        Queue::fake();
        $this->withPusher();
        config(['queue.default' => 'database']);
        Cache::flush();

        foreach (range(1, 3) as $change) {
            RecordsChanged::announce('matters');
        }

        // The first at once; the rest as one more, by the queue, after the wait.
        Event::assertDispatchedTimes(RecordsChanged::class, 1);
        Queue::assertPushed(CallQueuedClosure::class, 1);

        RecordsChanged::announceLater('matters');
        Event::assertDispatchedTimes(RecordsChanged::class, 2);

        // And a change after that one is held back in its turn, not lost.
        RecordsChanged::announce('matters');
        Queue::assertPushed(CallQueuedClosure::class, 2);
    }

    public function test_a_record_saved_with_nothing_changed_is_not_announced(): void
    {
        $this->withPusher();
        $matter = Matter::factory()->create()->fresh();
        Cache::flush();
        Event::fake([RecordsChanged::class]);

        $matter->save();
        Event::assertNotDispatched(RecordsChanged::class);

        $matter->forceFill(['updated_at' => now()->addMinute()])->save();
        Event::assertDispatchedTimes(RecordsChanged::class, 1);
    }

    public function test_the_dashboard_tables_and_calendars_are_told_too(): void
    {
        $this->withPusher();

        $table = Livewire::test(UpcomingSessionsWidget::class)->instance();
        $this->assertSame('$refresh', (fn () => $this->getListeners())->call($table)['echo-private:records,.records.changed']);

        $calendar = Livewire::test(VacationCalendarWidget::class)->instance();
        $this->assertSame('refreshRecords', (fn () => $this->getListeners())->call($calendar)['echo-private:records,.records.changed']);
    }

    public function test_a_resources_list_names_its_table_for_the_page_to_read_it_again(): void
    {
        $this->withPusher(false);
        $this->assertSame('', Live::listMarker([ListMatters::class, MatterResource::class]));

        $this->withPusher();
        $this->assertStringContainsString('data-wakeel-live-table="matters"', Live::listMarker([ListMatters::class, MatterResource::class]));
        $this->assertSame('', Live::listMarker([ListMatters::class]));
    }
}
