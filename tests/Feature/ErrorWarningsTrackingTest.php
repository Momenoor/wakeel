<?php

namespace Tests\Feature;

use App\Filament\Shared\Resources\ErrorReports\ErrorReportResource;
use App\Filament\Shared\Resources\ErrorReports\Pages\ListErrorReports;
use App\Models\ErrorReport;
use App\Models\User;
use App\Services\MMS\OneDriveAddedEmails;
use App\Support\ErrorReportMarkdown;
use App\Support\ErrorTracker;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Warnings on Settings → Error tracking (ErrorTracker::warning): a background request that failed in the browser,
 * emails in a matter's OneDrive that could not be read — the same one again counted on its row, not a new row —
 * and every unresolved problem exported as one Markdown file.
 */
class ErrorWarningsTrackingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('mms'));
        Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('super-admin');
    }

    public function test_a_request_that_failed_in_the_browser_is_a_warning_counted_not_repeated(): void
    {
        $this->actingAs($this->admin);

        $this->postJson(route('client-errors.store'), ['status' => 500, 'name' => 'matters.edit-matter@save', 'page' => 'https://wakeel.test/mms/matters/12/edit', 'ms' => 900])->assertNoContent();
        $this->travel(5)->minutes();
        // Another matter's page: the same problem.
        $this->postJson(route('client-errors.store'), ['status' => 500, 'name' => 'matters.edit-matter@save', 'page' => 'https://wakeel.test/mms/matters/345/edit', 'ms' => 700])->assertNoContent();

        $warning = ErrorReport::query()->sole();
        $this->assertSame('warning', $warning->level);
        $this->assertSame('browser', $warning->source);
        $this->assertSame(2, $warning->times);
        $this->assertSame(500, $warning->status);
        $this->assertSame($this->admin->id, $warning->user_id);
        $this->assertStringContainsString('matters.edit-matter@save', $warning->message);
        // The latest time's page and context.
        $this->assertSame('/mms/matters/345/edit', $warning->input['page']);
        $this->assertStringContainsString('/mms/matters/345/edit', $warning->url);
        $this->assertTrue($warning->last_seen_at->gt($warning->created_at));

        // Another status, or no answer at all: another warning.
        $this->postJson(route('client-errors.store'), ['status' => 403, 'name' => 'matters.edit-matter@save', 'page' => 'https://wakeel.test/mms/matters/12/edit'])->assertNoContent();
        $this->postJson(route('client-errors.store'), ['status' => 0, 'name' => 'matters.edit-matter@save', 'page' => 'https://wakeel.test/mms/matters/12/edit'])->assertNoContent();

        $this->assertSame(3, ErrorReport::query()->count());
        $this->assertStringContainsString('(no answer)', ErrorReport::query()->latest('id')->first()->message);
    }

    public function test_a_warning_resolved_and_seen_again_comes_back_as_a_new_one(): void
    {
        $first = ErrorTracker::warning('OneDrive emails not read', 'Folder 77 not found', ['matter' => 77], 'x');
        $first->resolveAll();

        $again = ErrorTracker::warning('OneDrive emails not read', 'Folder 77 not found', ['matter' => 77], 'x');

        $this->assertNotSame($first->id, $again->id);
        $this->assertNotSame($first->code, $again->code);
        $this->assertSame(1, ErrorReport::query()->unresolved()->count());
    }

    public function test_onedrive_throttling_is_one_warning_while_a_missing_folder_is_one_for_each_matter(): void
    {
        $throttled = new RuntimeException('Microsoft Graph 429: Too many requests');
        OneDriveAddedEmails::track('Emails added in OneDrive not read for matter 2025/10: '.$throttled->getMessage(), $throttled, ['matter' => 1]);
        OneDriveAddedEmails::track('Emails added in OneDrive not read for matter 2025/11: '.$throttled->getMessage(), $throttled, ['matter' => 2]);

        $missing = new RuntimeException('Microsoft Graph 404: The resource could not be found.');
        OneDriveAddedEmails::track('Emails added in OneDrive not read: '.$missing->getMessage(), $missing, ['folder' => 5]);
        OneDriveAddedEmails::track('Emails added in OneDrive not read: '.$missing->getMessage(), $missing, ['folder' => 5]);
        OneDriveAddedEmails::track('Emails added in OneDrive not read: '.$missing->getMessage(), $missing, ['folder' => 6]);

        $this->assertSame(3, ErrorReport::query()->count());
        $this->assertSame(2, ErrorReport::query()->where('status', 429)->sole()->times);
        $this->assertEqualsCanonicalizing([2, 1], ErrorReport::query()->where('status', 404)->pluck('times')->all());
        $this->assertSame(['warning'], ErrorReport::query()->distinct()->pluck('level')->all());
    }

    public function test_the_screen_shows_the_times_a_warning_was_seen_and_filters_warnings_from_errors(): void
    {
        $warning = ErrorTracker::warning('Browser request failed', 'A background request failed', [], 'a');
        ErrorTracker::warning('Browser request failed', 'A background request failed', [], 'a');
        ErrorTracker::warning('Browser request failed', 'A background request failed', [], 'a');
        ErrorTracker::record(new RuntimeException('A real error'));
        $error = ErrorReport::query()->where('level', 'error')->sole();

        Livewire::actingAs($this->admin)
            ->test(ListErrorReports::class)
            ->assertCanSeeTableRecords([$warning, $error])
            ->assertTableColumnStateSet('occurrences', 3, $warning)
            ->filterTable('level', 'warning')
            ->assertCanSeeTableRecords([$warning])
            ->assertCanNotSeeTableRecords([$error]);

        $this->assertSame('2', ErrorReportResource::getNavigationBadge());
    }

    public function test_every_unresolved_problem_is_exported_as_one_markdown_file(): void
    {
        ErrorTracker::warning('OneDrive emails not read', 'Emails added in OneDrive not read: Microsoft Graph 429', ['matter' => 4], 'throttled', 429);
        // The latest time's context, without its secrets.
        ErrorTracker::warning('OneDrive emails not read', 'Emails added in OneDrive not read: Microsoft Graph 429', ['matter' => 9, 'token' => 'secret'], 'throttled', 429);
        ErrorTracker::record(new RuntimeException('Something | broke'));
        ErrorTracker::record(new RuntimeException('Already fixed'));
        ErrorReport::query()->where('message', 'Already fixed')->sole()->resolveAll();

        $markdown = ErrorReportMarkdown::make();

        $this->assertStringStartsWith('# Unresolved errors', $markdown);
        $this->assertStringContainsString('- Problems: 2 (1 errors, 1 warnings), 3 times in all', $markdown);
        $this->assertStringContainsString('## 1. Warning: Emails added in OneDrive not read: Microsoft Graph 429', $markdown);
        $this->assertStringContainsString('| Times | 2 |', $markdown);
        $this->assertStringContainsString('| HTTP status | 429 |', $markdown);
        $this->assertStringContainsString('"matter": 9', $markdown);
        $this->assertStringContainsString('"token": "***"', $markdown);
        $this->assertStringContainsString('## 2. Error: Something | broke', $markdown);
        $this->assertStringContainsString('**Trace**', $markdown);
        $this->assertStringNotContainsString('Already fixed', $markdown);

        Livewire::actingAs($this->admin)
            ->test(ListErrorReports::class)
            ->callAction('exportMarkdown')
            ->assertHasNoActionErrors()
            ->assertFileDownloaded(contentType: 'text/markdown; charset=UTF-8');
    }

    public function test_a_warning_still_being_seen_is_not_pruned(): void
    {
        $old = ErrorTracker::warning('Browser request failed', 'Old and gone', [], 'a');
        $still = ErrorTracker::warning('Browser request failed', 'Old but still seen', [], 'b');
        ErrorReport::query()->update(['created_at' => now()->subDays(ErrorReport::KEEP_DAYS + 5), 'last_seen_at' => now()->subDays(ErrorReport::KEEP_DAYS + 1)]);
        $still->update(['last_seen_at' => now()->subDay()]);

        $this->assertSame(1, ErrorReport::prune());
        $this->assertNull($old->fresh());
        $this->assertNotNull($still->fresh());
    }
}
