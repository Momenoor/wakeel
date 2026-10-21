<?php

namespace Tests\Feature;

use App\Models\LetterTemplate;
use App\Models\Matter;
use App\Models\MatterMinutes;
use App\Models\Party;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The phone and email typed for an attendee, copied before onto the party they attended for, taken off it
 * by the update — only those copied, not one the party had of its own before — and kept to put back.
 */
class AttendeeContactsCleanupTest extends TestCase
{
    use RefreshDatabase;

    public function test_contacts_copied_onto_the_party_attended_for_are_taken_off_it(): void
    {
        // The company's own, months before.
        $this->travelTo('2026-05-01 10:00:00');
        $company = Party::factory()->create(['name' => 'شركة المهاد', 'phone' => ['042223333'], 'email' => ['shared@office.ae']]);

        // The meeting: the agent's own typed, copied onto the company the same moment.
        $this->travelTo('2026-10-21 09:00:00');
        $agent = Party::factory()->create(['name' => 'سالم الجديد', 'phone' => ['0507778899'], 'email' => ['salem@new.ae', 'shared@office.ae']]);
        $company->addContact('0507778899', 'salem@new.ae');
        $company->addContact(null, 'shared@office.ae');
        MatterMinutes::create([
            'matter_id' => Matter::factory()->create()->id, 'letter_template_id' => LetterTemplate::query()->value('id'), 'number' => 1,
            'meeting_at' => '2026-10-21 08:00:00', 'status' => MatterMinutes::FINAL,
            'attendees' => [['present' => true, 'name' => 'سالم الجديد', 'party_id' => $agent->id, 'represents' => $company->id,
                'phone' => '0507778899', 'email' => 'salem@new.ae'],
                ['present' => true, 'name' => 'سالم الجديد', 'party_id' => $agent->id, 'represents' => $company->id, 'email' => 'shared@office.ae']],
        ]);

        $migration = require database_path('migrations/2020_01_01_000181_attendee_contacts_kept_as_theirs.php');
        $migration->up();

        // The agent's taken off the company; the company's own office email, which it had before, kept.
        $company->refresh();
        $this->assertSame(['042223333'], $company->phone);
        $this->assertSame(['shared@office.ae'], $company->email);
        // The agent keeps theirs.
        $this->assertSame(['0507778899'], $agent->fresh()->phone);
        // What was taken off, kept — and put back on undo.
        $this->assertCount(2, Setting::get('minutes_attendee_contacts_moved'));
        $migration->down();
        $this->assertContains('salem@new.ae', $company->fresh()->email);
    }
}
