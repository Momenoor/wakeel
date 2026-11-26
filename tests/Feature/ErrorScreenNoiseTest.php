<?php

namespace Tests\Feature;

use App\Http\Controllers\ClientErrorController;
use App\Models\ErrorReport;
use App\Models\Matter;
use App\Models\MatterOneDriveFolder;
use App\Models\Party;
use App\Models\PerformanceSample;
use App\Models\Setting;
use App\Models\User;
use App\Services\MMS\MatterOneDriveFolders;
use App\Services\MMS\OneDriveAddedEmails;
use App\Services\MMS\OneDriveClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use RuntimeException;
use Tests\TestCase;

/**
 * Settings → Error tracking keeps to what is worth looking into: a browser request cut off at once (status 0, the
 * user's connection) only on the Performance page, and Microsoft 365 throttling that passes by itself not a warning.
 */
class ErrorScreenNoiseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.outlook' => ['tenant_id' => 't', 'client_id' => 'c', 'client_secret' => 's']]);
        Sleep::fake();
    }

    public function test_a_browser_request_with_no_answer_at_once_is_not_on_the_error_screen(): void
    {
        $this->actingAs(User::factory()->create());

        foreach (['echo: connection failed', 'echo: connection unavailable', 'App\Livewire\TabbedDatabaseNotifications@__dispatch', 'chat-widget@setOpen'] as $name) {
            $this->postJson(route('client-errors.store'), ['status' => 0, 'name' => $name, 'page' => 'https://wakeel.test/mms/chat', 'ms' => 2])->assertNoContent();
        }

        $this->assertSame(0, ErrorReport::query()->count());
        // Still on the Performance page.
        $this->assertSame(4, PerformanceSample::query()->where('method', 'CLIENT')->where('status', 0)->count());
    }

    public function test_no_answer_after_a_long_wait_or_an_error_answer_is_still_a_warning(): void
    {
        $this->actingAs(User::factory()->create());

        $this->postJson(route('client-errors.store'), ['status' => 0, 'name' => 'matters.edit-matter@save', 'page' => 'https://wakeel.test/mms/matters/1/edit', 'ms' => ClientErrorController::HUNG_MS])->assertNoContent();
        $this->postJson(route('client-errors.store'), ['status' => 500, 'name' => 'matters.edit-matter@save', 'page' => 'https://wakeel.test/mms/matters/1/edit', 'ms' => 3])->assertNoContent();

        $this->assertSame(2, ErrorReport::query()->where('level', 'warning')->count());
    }

    public function test_the_no_answer_warnings_already_on_the_screen_are_marked_dealt_with(): void
    {
        $row = fn (array $input, ?int $status = null) => ErrorReport::create([
            'code' => 'E-'.fake()->unique()->bothify('??????'), 'fingerprint' => fake()->sha1(), 'level' => 'warning', 'times' => 1, 'source' => 'browser',
            'status' => $status, 'exception' => 'Browser request failed', 'message' => 'A background request failed', 'input' => $input,
        ]);
        $quick = $row(['status' => 0, 'ms' => 2]);
        $hung = $row(['status' => 0, 'ms' => 45_000]);
        $answered = $row(['status' => 500, 'ms' => 2], 500);

        (require database_path('migrations/2020_01_01_000257_resolve_browser_no_answer_warnings.php'))->up();

        $this->assertNotNull($quick->fresh()->resolved_at);
        $this->assertNull($hung->fresh()->resolved_at);
        $this->assertNull($answered->fresh()->resolved_at);
    }

    /** Two matters' folders; the client turns every folder back with 429 until $throttled is false. */
    private function twoFolders(): object
    {
        Setting::set(MatterOneDriveFolders::ENABLED, true, 'onedrive');
        Setting::clearCache();
        $party = Party::factory()->assistant()->create(['onedrive_email' => 'amr@firm.ae']);

        foreach (['a', 'b'] as $id) {
            MatterOneDriveFolder::create([
                'matter_id' => Matter::factory()->create()->id, 'folder_name' => 'folder '.$id, 'status' => MatterOneDriveFolder::CREATED,
                'drive_item_id' => $id, 'party_id' => $party->id,
            ]);
        }

        $client = new class extends OneDriveClient
        {
            public bool $throttled = true;

            public array $asked = [];

            public function isConfigured(): bool
            {
                return true;
            }

            public function childrenOfMany(string $user, array $folderIds): array
            {
                $this->asked = [...$this->asked, ...$folderIds];

                if ($this->throttled) {
                    throw new RuntimeException('Microsoft Graph 429: The request has been throttled');
                }

                return array_fill_keys($folderIds, []);
            }
        };
        $this->app->instance(OneDriveClient::class, $client);

        return $client;
    }

    public function test_throttling_stops_the_run_and_the_folder_is_read_first_next_time(): void
    {
        $client = $this->twoFolders();

        $result = app(OneDriveAddedEmails::class)->readDue();

        $this->assertTrue($result['stopped']);
        $this->assertSame(1, $result['folders']);
        // Only the first folder asked; it is not marked read, so it stays first.
        $this->assertSame(['a'], $client->asked);
        $this->assertNull(MatterOneDriveFolder::query()->where('drive_item_id', 'a')->value('emails_read_at'));

        // Passing by itself: no warning.
        $this->assertSame(0, ErrorReport::query()->count());

        $client->throttled = false;
        $this->travel(30)->minutes();
        $result = app(OneDriveAddedEmails::class)->readDue();
        $this->assertSame(2, $result['folders']);
        $this->assertSame(['a', 'a', 'b'], $client->asked);
        $this->assertSame(0, ErrorReport::query()->count());
    }

    public function test_throttling_that_lasts_is_a_warning(): void
    {
        $this->twoFolders();

        app(OneDriveAddedEmails::class)->readDue();
        $this->travel(OneDriveAddedEmails::THROTTLED_WARN_MINUTES - 30)->minutes();
        app(OneDriveAddedEmails::class)->readDue();
        $this->assertSame(0, ErrorReport::query()->count());

        $this->travel(30)->minutes();
        app(OneDriveAddedEmails::class)->readDue();
        $warning = ErrorReport::query()->sole();
        $this->assertSame(429, $warning->status);
        $this->assertArrayHasKey('throttled_since', $warning->input);
    }

    public function test_folders_asked_at_once_and_throttled_wait_once_before_being_asked_again(): void
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['access_token' => 'token']),
            'graph.microsoft.com/*' => Http::sequence()
                // The two asked at once: both turned back.
                ->push(['error' => ['message' => 'The request has been throttled']], 429, ['Retry-After' => '4'])
                ->push(['error' => ['message' => 'The request has been throttled']], 429, ['Retry-After' => '2'])
                // Then each, after the wait, answers.
                ->push(['value' => []])
                ->push(['value' => []]),
        ]);

        $listings = app(OneDriveClient::class)->childrenOfMany('amr@firm.ae', ['a', 'b']);

        $this->assertSame(['a' => [], 'b' => []], $listings);
        Sleep::assertSleptTimes(1);
        Sleep::assertSlept(fn ($duration): bool => (int) $duration->totalSeconds === 4, 1);
    }
}
