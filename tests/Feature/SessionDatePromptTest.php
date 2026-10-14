<?php

namespace Tests\Feature;

use App\Filament\Mms\Resources\Matters\Pages\EditMatter;
use App\Models\CalendarEvent;
use App\Models\Matter;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Saving a matter with a new session date offers to put that session on the
 * calendar — once, and only when it is not there already.
 */
class SessionDatePromptTest extends TestCase
{
    use RefreshDatabase;

    private Matter $matter;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('mms');
        Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        $admin = User::factory()->create();
        $admin->assignRole('super-admin');
        $this->actingAs($admin);

        $this->matter = Matter::factory()->create(['next_session_date' => '2026-11-01 10:00:00', 'level' => 'congestion']);
    }

    public function test_a_changed_session_date_offers_a_calendar_event(): void
    {
        Livewire::test(EditMatter::class, ['record' => $this->matter->getKey()])
            ->fillForm(['next_session_date' => '2026-12-05 11:00'])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertActionMounted('confirmCreateCalendarEvent');
    }

    public function test_saving_without_changing_the_date_offers_nothing(): void
    {
        Livewire::test(EditMatter::class, ['record' => $this->matter->getKey()])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertSet('mountedActions', []);
    }

    public function test_a_date_that_already_has_an_event_offers_nothing(): void
    {
        CalendarEvent::create([
            'title' => 'Session', 'matter_id' => $this->matter->getKey(),
            'start_datetime' => '2026-12-05 11:00:00', 'type' => 'single',
        ]);

        Livewire::test(EditMatter::class, ['record' => $this->matter->getKey()])
            ->fillForm(['next_session_date' => '2026-12-05 11:00'])
            ->call('save')
            ->assertSet('mountedActions', []);
    }
    public function test_saving_the_form_keeps_the_session_time_as_it_was(): void
    {
        $this->matter->update(['next_session_date' => '2026-11-01 15:30:00']);

        Livewire::test(EditMatter::class, ['record' => $this->matter->getKey()])->call('save');

        // The date field used to be written h:m — a 12-hour clock with the month
        // for minutes — so every save turned 15:30 into 03:11.
        $this->assertSame('2026-11-01 15:30:00', $this->matter->fresh()->getRawOriginal('next_session_date'));
    }
}
