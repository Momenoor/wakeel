<?php

namespace Tests\Feature;

use App\Enums\RequestStatus;
use App\Enums\RequestType;
use App\Filament\Mms\Resources\CalendarEvents\CalendarEventResource;
use App\Filament\Mms\Resources\CalendarEvents\Pages\ListCalendarEvents;
use App\Filament\Mms\Resources\Courts\CourtResource;
use App\Filament\Mms\Resources\LetterTemplates\LetterTemplateResource;
use App\Filament\Mms\Resources\MatterRequests\MatterRequestResource;
use App\Filament\Mms\Resources\Matters\MatterResource;
use App\Filament\Mms\Resources\Types\TypeResource;
use App\Models\CalendarEvent;
use App\Models\Court;
use App\Models\Matter;
use App\Models\MatterRequest;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Global search (Ctrl/Cmd+K): on in the panels, limited to the resources
 * that opt in, and a matter is found by "number/year", year and number in
 * any order, a party or its court.
 */
class GlobalSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('mms'));

        Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        $admin = User::factory()->create();
        $admin->assignRole('super-admin');
        $this->actingAs($admin);
    }

    public function test_it_is_on_and_searches_only_the_resources_that_opted_in(): void
    {
        $this->assertNotNull(Filament::getPanel('mms')->getGlobalSearchProvider());
        $this->assertNotNull(Filament::getPanel('pms')->getGlobalSearchProvider());
        $this->assertTrue(MatterResource::canGloballySearch());
        $this->assertTrue(CourtResource::canGloballySearch());
        $this->assertFalse(TypeResource::canGloballySearch());
        $this->assertFalse(LetterTemplateResource::canGloballySearch());
    }

    public function test_every_searchable_resource_runs_its_search(): void
    {
        foreach (['mms', 'pms'] as $panel) {
            Filament::setCurrentPanel(Filament::getPanel($panel));

            $resources = array_filter(Filament::getCurrentPanel()->getResources(), fn ($resource) => $resource::canGloballySearch());

            $this->assertNotEmpty($resources, $panel);

            foreach ($resources as $resource) {
                $this->assertCount(0, $resource::getGlobalSearchResults('nothing-matches-this'), $resource);
            }
        }
    }

    public function test_it_finds_a_past_event_by_title_or_its_matter_and_opens_it(): void
    {
        $matter = Matter::factory()->create(['number' => '4321', 'year' => 2024]);
        $event = CalendarEvent::create([
            'title' => 'Site inspection visit',
            'start_datetime' => now()->subMonth(),
            'end_datetime' => now()->subMonth()->addHour(),
            'type' => 'single',
        ]);
        $event->matters()->attach($matter);

        foreach (['inspection', '4321/2024'] as $search) {
            $results = CalendarEventResource::getGlobalSearchResults($search);
            $this->assertSame(['Site inspection visit'], $results->pluck('title')->all(), $search);
        }

        // Opens in the list's view modal, though the list shows upcoming events by default.
        $page = Livewire::test(ListCalendarEvents::class)
            ->mountTableAction('view', $event->getKey())
            ->assertHasNoErrors();

        $this->assertTrue($page->instance()->getMountedAction()?->getRecord()?->is($event));
    }

    public function test_it_finds_a_matter_request_by_its_matter_kind_or_requester(): void
    {
        $matter = Matter::factory()->create(['number' => '4321', 'year' => 2024]);
        $requester = User::factory()->create(['name' => 'Requesting Expert']);
        $request = MatterRequest::create([
            'matter_id' => $matter->id,
            'request_by' => $requester->id,
            'type' => RequestType::CHANGE_DIFFICULTY,
            'status' => RequestStatus::PENDING,
            'comment' => 'Harder than it looked',
        ]);
        MatterRequest::create([
            'matter_id' => Matter::factory()->create(['number' => '999', 'year' => 2023])->id,
            'request_by' => auth()->id(),
            'type' => RequestType::REVIEW_REPORT,
            'status' => RequestStatus::PENDING,
            'comment' => 'Another one',
        ]);

        $title = RequestType::CHANGE_DIFFICULTY->getLabel().' — 2024/4321';

        foreach (['4321/2024', 'Requesting', 'Harder', RequestType::CHANGE_DIFFICULTY->getLabel()] as $search) {
            $results = MatterRequestResource::getGlobalSearchResults($search);
            $this->assertSame([$title], $results->pluck('title')->all(), $search);
        }

        $this->assertSame(MatterRequestResource::getUrl('view', ['record' => $request]), MatterRequestResource::getGlobalSearchResults('4321')->first()->url);
    }

    public function test_it_finds_a_matter_by_number_and_year_or_its_court(): void
    {
        $court = Court::factory()->create(['name' => 'Uniquely Named Court']);
        Matter::factory()->create(['number' => '4321', 'year' => 2024, 'court_id' => $court->id]);
        Matter::factory()->create(['number' => '999', 'year' => 2023]);

        $titles = fn (string $search) => MatterResource::getGlobalSearchResults($search)->pluck('title')->all();

        $this->assertSame(['2024/4321'], $titles('4321/2024'));
        $this->assertSame(['2024/4321'], $titles('2024 4321'));
        $this->assertSame(['2024/4321'], $titles('Uniquely'));
        $this->assertArrayHasKey(__('Court'), MatterResource::getGlobalSearchResults('4321')->first()->details);
    }
}
