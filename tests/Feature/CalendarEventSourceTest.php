<?php

namespace Tests\Feature;

use App\Enums\CalendarEventSource;
use App\Filament\Mms\Resources\CalendarEvents\Pages\ListCalendarEvents;
use App\Filament\Mms\Widgets\CalendarWidget;
use App\Models\CalendarEvent;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Each event says where it was made: in the system, or outside it (Outlook, Teams, a phone — brought in by the
 * sync). On the list (a badge and a filter), on the calendar (outside ones in grey) and in the tooltip.
 */
class CalendarEventSourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_event_says_where_it_was_made(): void
    {
        Gate::before(fn () => true);
        Filament::setCurrentPanel('mms');
        $this->actingAs(User::factory()->create());

        $ours = CalendarEvent::create(['title' => 'Made here', 'start_datetime' => now()->addDay(), 'end_datetime' => now()->addDay()->addHour(), 'type' => 'single']);
        $theirs = CalendarEvent::create(['title' => 'Made in Outlook', 'start_datetime' => now()->addDays(2), 'end_datetime' => now()->addDays(2)->addHour(), 'type' => 'single', 'imported_from_outlook' => true]);

        $this->assertSame([CalendarEventSource::SYSTEM, CalendarEventSource::OUTSIDE], [$ours->source(), $theirs->source()]);

        Livewire::test(ListCalendarEvents::class)
            ->assertSee(CalendarEventSource::SYSTEM->getLabel())
            ->assertSee(CalendarEventSource::OUTSIDE->getLabel())
            ->filterTable('source', CalendarEventSource::OUTSIDE->value)
            ->assertCanSeeTableRecords([$theirs])
            ->assertCanNotSeeTableRecords([$ours])
            ->filterTable('source', CalendarEventSource::SYSTEM->value)
            ->assertCanSeeTableRecords([$ours])
            ->assertCanNotSeeTableRecords([$theirs]);

        // On the calendar: outside ones in grey, and said in the tooltip.
        $events = collect((new CalendarWidget)->fetchEvents(['start' => now()->toDateTimeString(), 'end' => now()->addWeek()->toDateTimeString()]))->keyBy('id');
        $this->assertSame('#6b7280', $events[$theirs->id]['backgroundColor']);
        $this->assertArrayNotHasKey('backgroundColor', $events[$ours->id]);
        $this->assertSame(CalendarEventSource::OUTSIDE->getLabel(), $events[$theirs->id]['extendedProps']['source']);
    }
}
