<?php

namespace Tests\Feature;

use App\Filament\Mms\Resources\CalendarEvents\Pages\ListCalendarEvents;
use App\Filament\Mms\Resources\Matters\Pages\ViewMatter;
use App\Models\CalendarEvent;
use App\Models\Court;
use App\Models\Matter;
use App\Models\MatterParty;
use App\Models\Party;
use App\Models\Type;
use App\Models\User;
use App\Services\MMS\Calendar\EventTitle;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Every single event's title is made the same way, from a letter, the matter
 * or the calendar: "571/2009 - محاكم دبي - تركات مسلمين - (م.ن)".
 */
class CalendarEventTitleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        $admin = User::factory()->create();
        $admin->assignRole('super-admin');
        $this->actingAs($admin);
    }

    private function matter(?string $assistant = 'مؤمن نور'): Matter
    {
        $matter = Matter::factory()->create([
            'number' => '571',
            'year' => '2009',
            'court_id' => Court::factory()->create(['name' => 'محاكم دبي'])->id,
            'type_id' => Type::factory()->create(['name' => 'تركات مسلمين'])->id,
        ]);

        if ($assistant !== null) {
            MatterParty::create([
                'matter_id' => $matter->id,
                'party_id' => Party::factory()->assistant()->create(['name' => $assistant])->id,
                'role' => 'expert',
                'type' => 'assistant',
            ]);
        }

        return $matter->fresh();
    }

    public function test_the_title_is_reference_court_type_and_assistant_initials(): void
    {
        $this->assertSame('571/2009 - محاكم دبي - تركات مسلمين - (م.ن)', EventTitle::for($this->matter()));
    }

    public function test_initials_take_the_first_and_last_names(): void
    {
        $this->assertSame('م.ن', EventTitle::initials('مؤمن نور'));
        $this->assertSame('م.ن', EventTitle::initials('  مؤمن  أحمد   نور '));
        $this->assertSame('م', EventTitle::initials('مؤمن'));
        $this->assertSame('', EventTitle::initials('  '));
    }

    public function test_missing_parts_are_left_out(): void
    {
        $this->assertSame('571/2009 - محاكم دبي - تركات مسلمين', EventTitle::for($this->matter(assistant: null)));

        $bare = Matter::factory()->create(['number' => '12', 'year' => '2024', 'court_id' => null, 'type_id' => null]);
        $this->assertSame('12/2024', EventTitle::for($bare));
    }

    public function test_an_event_made_from_the_matter_page_has_the_title(): void
    {
        $matter = $this->matter();

        Livewire::test(ViewMatter::class, ['record' => $matter->getRouteKey()])
            ->mountAction('createCalendarEvent')
            ->assertSet('mountedActions.0.data.title', '571/2009 - محاكم دبي - تركات مسلمين - (م.ن)')
            ->set('mountedActions.0.data.start_datetime', '2026-12-01 10:00:00')
            ->set('mountedActions.0.data.end_datetime', '2026-12-01 11:00:00')
            ->set('mountedActions.0.data.sync_to_outlook', false)
            ->callMountedAction()
            ->assertHasNoFormErrors();

        $this->assertSame('571/2009 - محاكم دبي - تركات مسلمين - (م.ن)', CalendarEvent::sole()->title);
    }

    public function test_picking_the_matter_on_the_calendar_gives_the_title(): void
    {
        $matter = $this->matter();

        Livewire::test(ListCalendarEvents::class)
            ->mountAction(TestAction::make('createSingle')->table())
            ->set('mountedActions.0.data.matter_id', $matter->id)
            ->assertSet('mountedActions.0.data.title', '571/2009 - محاكم دبي - تركات مسلمين - (م.ن)');
    }
}
