<?php

namespace Tests\Feature\Letters;

use App\Filament\Mms\Resources\Matters\Pages\ViewMatter;
use App\Filament\Mms\Resources\Matters\RelationManagers\LettersRelationManager;
use App\Models\CalendarEvent;
use App\Models\LetterTemplate;
use App\Models\Matter;
use App\Models\MatterLetter;
use App\Models\MatterParty;
use App\Models\Party;
use App\Models\User;
use App\Services\MMS\Letters\LetterComposer;
use App\Services\MMS\Letters\LetterIssuer;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Issuing a letter with its Teams meeting: Microsoft is called with no database transaction or calendar row held
 * open meanwhile — held, MySQL dropped the idle connection ("2006 MySQL server has gone away") and the event's
 * clean-up (delete from calendar_events) failed with it. A failure on either side leaves nothing behind.
 */
class LetterMeetingConnectionTest extends TestCase
{
    use RefreshDatabase;

    private const JOIN_URL = 'https://teams.microsoft.com/l/meetup-join/19%3ameeting_abc%40thread.v2/0';

    private Matter $matter;

    private LetterTemplate $template;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create()->assignRole(
            Role::firstOrCreate(['name' => config('filament-shield.super_admin.name', 'super_admin'), 'guard_name' => 'web'])
        ));
        Filament::setCurrentPanel('admin');

        config(['services.outlook' => ['tenant_id' => 't', 'client_id' => 'c', 'client_secret' => 's', 'user_email' => 'office@jpa.ae']]);

        $this->matter = Matter::factory()->create(['number' => '571', 'year' => '2009']);
        MatterParty::create(['matter_id' => $this->matter->id, 'role' => 'party', 'type' => 'plaintiff',
            'party_id' => Party::factory()->create(['name' => 'منى أحمد', 'email' => ['mona@example.com']])->id]);

        $this->template = LetterTemplate::create([
            'name' => 'دعوة لاجتماع', 'slug' => 'meeting', 'locale' => 'ar', 'category' => 'letter', 'subject' => 'دعوة',
            'inputs' => [
                ['key' => 'meeting_date', 'label' => 'تاريخ الاجتماع', 'type' => 'date'],
                ['key' => 'meeting_time', 'label' => 'الوقت', 'type' => 'time'],
            ],
            'body' => '<p>{{recipients}}</p><p>{{meeting.link}}</p>',
        ]);
    }

    private function issue(): Testable
    {
        return Livewire::test(LettersRelationManager::class, ['ownerRecord' => $this->matter, 'pageClass' => ViewMatter::class])
            ->callAction(TestAction::make('issue')->table(), [
                'letter_template_id' => $this->template->id,
                'recipients' => array_keys(LetterComposer::candidates($this->matter)),
                'inputs' => ['meeting_date' => '2026-12-01', 'meeting_time' => '10:30'],
                'create_meeting' => true,
            ]);
    }

    public function test_outlook_is_called_with_no_transaction_and_no_event_row_held_open(): void
    {
        // The test's own transaction (RefreshDatabase): anything above it is the action's.
        $baseline = DB::transactionLevel();
        $seen = [];

        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['access_token' => 'token']),
            'graph.microsoft.com/*' => function (Request $request) use (&$seen, $baseline) {
                if ($request->method() === 'POST' && str_ends_with($request->url(), '/events')) {
                    $seen = ['level' => DB::transactionLevel() - $baseline, 'events' => CalendarEvent::withTrashed()->count()];
                }

                return Http::response(['id' => 'outlook-1', 'onlineMeeting' => ['joinUrl' => self::JOIN_URL]], 201);
            },
        ]);

        $this->issue()->assertHasNoFormErrors();

        $this->assertSame(['level' => 0, 'events' => 0], $seen);

        // Saved in one go, after Outlook answered.
        $event = CalendarEvent::sole();
        $this->assertSame('outlook-1', $event->outlook_event_id);
        $this->assertTrue($event->synced_to_outlook);
        $this->assertSame(self::JOIN_URL, $event->online_meeting_url);
        $this->assertStringContainsString(e(self::JOIN_URL), MatterLetter::sole()->rendered_html);
    }

    public function test_the_write_action_runs_outside_a_transaction_too(): void
    {
        $component = Livewire::test(LettersRelationManager::class, ['ownerRecord' => $this->matter, 'pageClass' => ViewMatter::class]);

        foreach (['issue', 'write'] as $name) {
            $component->mountAction(TestAction::make($name)->table());
            $this->assertFalse($component->instance()->getMountedAction()->hasDatabaseTransactions(), $name);
            $component->unmountAction();
        }
    }

    public function test_a_lost_connection_saving_the_event_says_why_and_takes_the_outlook_meeting_back(): void
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['access_token' => 'token']),
            'graph.microsoft.com/*' => Http::response(['id' => 'outlook-1', 'onlineMeeting' => ['joinUrl' => self::JOIN_URL]], 201),
        ]);

        // The database gone while Microsoft answered.
        CalendarEvent::creating(fn () => throw new QueryException('mysql', 'insert into `calendar_events`', [], new \PDOException('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away')));

        $this->issue()->assertNotified(__('The Teams meeting could not be created'));

        $this->assertSame(0, MatterLetter::count());
        $this->assertSame(0, CalendarEvent::withTrashed()->count());
        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' && str_ends_with($request->url(), '/events/outlook-1'));
    }

    public function test_a_letter_that_fails_to_issue_takes_its_new_meeting_with_it(): void
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['access_token' => 'token']),
            'graph.microsoft.com/*' => Http::response(['id' => 'outlook-1', 'onlineMeeting' => ['joinUrl' => self::JOIN_URL]], 201),
        ]);

        $this->mock(LetterIssuer::class)->shouldReceive('issue')->andThrow(new RuntimeException('The letter could not be saved'));

        $this->issue()->assertNotified(__('The Teams meeting could not be created'));

        $this->assertSame(0, MatterLetter::count());
        $this->assertSame(0, CalendarEvent::withTrashed()->count());
        // Nor in Outlook (CalendarEventObserver::deleted()).
        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' && str_ends_with($request->url(), '/events/outlook-1'));
    }
}
