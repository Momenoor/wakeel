<?php

namespace Tests\Feature;

use App\Models\Matter;
use App\Models\MatterOneDriveFolder;
use App\Models\Party;
use App\Models\Setting;
use App\Models\User;
use App\Services\MMS\MatterOneDriveFolders;
use App\Services\MMS\OneDriveAddedEmails;
use App\Services\MMS\OneDriveClient;
use App\Services\MMS\OneDriveNotFound;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * Warnings the production log filled with: Microsoft 365 throttling OneDrive (429) failing at once, a matter's
 * folder gone from OneDrive logged on every run (and called "no OneDrive for this account"), and the browser's
 * "no answer" (status 0) reports.
 */
class ProductionLogWarningsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.outlook' => ['tenant_id' => 't', 'client_id' => 'c', 'client_secret' => 's']]);
        Sleep::fake();
    }

    private static function token(): array
    {
        return ['login.microsoftonline.com/*' => Http::response(['access_token' => 'token'])];
    }

    public function test_onedrive_throttled_is_asked_again_after_the_wait_microsoft_names(): void
    {
        Http::fake(self::token() + ['graph.microsoft.com/*' => Http::sequence()
            ->push(['error' => ['code' => 'TooManyRequests', 'message' => 'The request has been throttled']], 429, ['Retry-After' => '3'])
            ->push(['value' => [['id' => 'f1', 'name' => 'Claim.eml', 'file' => [], 'size' => 10]]])]);

        $items = app(OneDriveClient::class)->children('amr@firm.ae', 'root-1');

        $this->assertSame(['Claim.eml'], array_column($items, 'name'));
        Sleep::assertSlept(fn ($duration): bool => (int) $duration->totalSeconds === 3, 1);
    }

    public function test_onedrive_still_throttled_or_asking_too_long_a_wait_fails(): void
    {
        Http::fake(self::token() + ['graph.microsoft.com/*' => Http::response(['error' => ['message' => 'The request has been throttled']], 429, ['Retry-After' => '1'])]);

        try {
            app(OneDriveClient::class)->children('amr@firm.ae', 'root-1');
            $this->fail('A request still throttled must fail.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Microsoft Graph 429', $e->getMessage());
        }

        // Three tries in all, two waits.
        Sleep::assertSleptTimes(2);
        Http::assertSentCount(4);
    }

    public function test_a_missing_folder_is_not_called_a_missing_onedrive(): void
    {
        Http::fake(self::token() + ['graph.microsoft.com/*' => Http::sequence()
            ->push(['error' => ['code' => 'itemNotFound', 'message' => 'The resource could not be found.']], 404)
            ->push(['error' => ['code' => 'ResourceNotFound', 'message' => 'User\'s mysite not found.']], 404)]);
        $client = app(OneDriveClient::class);

        $item = rescue(fn () => $client->itemInfo('amr@firm.ae', 'gone'), fn ($e) => $e, false);
        $drive = rescue(fn () => $client->itemInfo('amr@firm.ae', 'gone'), fn ($e) => $e, false);

        $this->assertInstanceOf(OneDriveNotFound::class, $item);
        $this->assertTrue($item->isItem());
        $this->assertStringContainsString(__('The folder or file is no longer in OneDrive: renamed, moved or deleted there.'), $item->getMessage());
        $this->assertStringContainsString(__('OneDrive not found for this account. Check the email on the assistant\'s profile, and that they have signed in to OneDrive at least once.'), $drive->getMessage());
    }

    public function test_a_folder_that_keeps_failing_is_logged_once_a_day_not_every_run(): void
    {
        $matter = Matter::factory()->create(['number' => '14', 'year' => '2026']);
        MatterOneDriveFolder::create([
            'matter_id' => $matter->id, 'folder_name' => '14-2026', 'status' => MatterOneDriveFolder::CREATED, 'drive_item_id' => 'gone',
            'party_id' => Party::factory()->assistant()->create(['onedrive_email' => 'amr@firm.ae'])->id,
        ]);
        Setting::set(MatterOneDriveFolders::ENABLED, true, 'onedrive');
        Setting::clearCache();

        $this->app->instance(OneDriveClient::class, new class extends OneDriveClient
        {
            public function isConfigured(): bool
            {
                return true;
            }

            public function childrenOfMany(string $user, array $folderIds): array
            {
                throw new OneDriveNotFound('The folder or file is no longer in OneDrive. The resource could not be found.', 'itemNotFound');
            }
        });
        Log::spy();

        $first = app(OneDriveAddedEmails::class)->readDue();
        app(OneDriveAddedEmails::class)->readDue();
        app(OneDriveAddedEmails::class)->readDue();

        // Each run still says why (the command's output), the log once.
        $this->assertCount(1, $first['errors']);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => str_starts_with($message, 'Emails added in OneDrive not read:'))->once();

        $this->travel(21)->hours();
        app(OneDriveAddedEmails::class)->readDue();
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => str_starts_with($message, 'Emails added in OneDrive not read:'))->twice();
    }

    public function test_a_browser_request_with_no_answer_is_not_a_warning_in_the_log(): void
    {
        $this->actingAs(User::factory()->create());
        Log::spy();

        $this->postJson(route('client-errors.store'), ['status' => 0, 'name' => 'echo: connection unavailable', 'page' => 'https://wakeel.test/mms'])->assertNoContent();
        Log::shouldNotHaveReceived('warning', ['A background request failed in the browser', \Mockery::any()]);

        $this->postJson(route('client-errors.store'), ['status' => 500, 'name' => 'letters@callMountedAction', 'page' => 'https://wakeel.test/mms/matters/86'])->assertNoContent();
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context = []): bool => $message === 'A background request failed in the browser' && $context['status'] === 500)->once();
    }
}
