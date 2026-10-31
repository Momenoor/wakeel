<?php

namespace Tests\Feature;

use App\Filament\Mms\Resources\CalendarEvents\Pages\ListCalendarEvents;
use App\Filament\Mms\Resources\Matters\Pages\ViewMatter;
use App\Filament\Mms\Resources\Matters\RelationManagers\EmailsRelationManager;
use App\Jobs\ReadMatterEmailsAddedInOneDrive;
use App\Jobs\SyncOutlookCalendarRange;
use App\Models\Matter;
use App\Models\User;
use App\Services\MMS\Calendar\OutlookCalendarSync;
use App\Services\MMS\OneDriveAddedEmails;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * From the performance report: "Sync with Outlook" (five to fourteen seconds) and "Read emails added in OneDrive"
 * (seconds) run on the queue, whoever asked told in the bell.
 */
class QueuedOutlookAndOneDriveTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('en');
        Gate::before(fn () => true);
        Filament::setCurrentPanel(Filament::getPanel('mms'));
        config(['services.outlook' => [
            'tenant_id' => 'tenant', 'client_id' => 'client', 'client_secret' => 'secret',
            'user_email' => 'calendar@firm.ae', 'redirect_uri' => null,
        ]]);
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    public function test_sync_with_outlook_runs_on_the_queue_and_tells_in_the_bell(): void
    {
        // The job: the whole days, what came of it in the bell.
        $this->mock(OutlookCalendarSync::class)
            ->shouldReceive('sync')
            ->once()
            ->withArgs(fn ($from, $to): bool => $from->format('Y-m-d H:i:s') === '2026-01-01 00:00:00' && $to->format('Y-m-d H:i:s') === '2026-03-31 23:59:59')
            ->andReturn(['added' => 2, 'updated' => 1, 'removed' => 0, 'linked' => 3]);

        (new SyncOutlookCalendarRange(CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-03-31'), $this->user->id))->handle(app(OutlookCalendarSync::class));

        $notification = $this->user->notifications()->sole()->data;
        $this->assertSame(__('Outlook sync done'), $notification['title']);
        $this->assertStringContainsString('2 added, 1 updated, 0 removed, 3 matter link(s)', $notification['body']);

        // The button: the job on the queue, said so at once.
        Bus::fake();

        Livewire::test(ListCalendarEvents::class)
            ->callAction(TestAction::make('syncWithOutlook')->table(), ['from' => '2026-01-01', 'to' => '2026-03-31'])
            ->assertHasNoActionErrors()
            ->assertNotified(__('Syncing with Outlook'));

        Bus::assertDispatched(SyncOutlookCalendarRange::class, fn (SyncOutlookCalendarRange $job): bool => $job->from->toDateString() === '2026-01-01'
            && $job->to->toDateString() === '2026-03-31'
            && $job->userId === $this->user->id);
    }

    public function test_reading_the_emails_added_in_onedrive_runs_on_the_queue_and_tells_in_the_bell(): void
    {
        $matter = Matter::factory()->create(['number' => '321', 'year' => '2026']);

        // The job: a matter with no OneDrive folder is told so, in the bell.
        (new ReadMatterEmailsAddedInOneDrive($matter->id, $this->user->id))->handle(app(OneDriveAddedEmails::class));

        $notification = $this->user->notifications()->sole()->data;
        $this->assertSame(__(':count emails added', ['count' => 0]), $notification['title']);
        $this->assertStringContainsString(__('The matter has no OneDrive folder.'), $notification['body']);

        // The button: the job on the queue, said so at once.
        Bus::fake();

        Livewire::test(EmailsRelationManager::class, ['ownerRecord' => $matter, 'pageClass' => ViewMatter::class])
            ->callAction(TestAction::make('readAddedEmails')->table())
            ->assertNotified(__('Reading the emails added in OneDrive'));

        Bus::assertDispatched(ReadMatterEmailsAddedInOneDrive::class, fn (ReadMatterEmailsAddedInOneDrive $job): bool => $job->matterId === $matter->id && $job->userId === $this->user->id);
    }
}
