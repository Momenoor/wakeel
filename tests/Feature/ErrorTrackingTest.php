<?php

namespace Tests\Feature;

use App\Filament\Shared\Resources\ErrorReports\ErrorReportResource;
use App\Filament\Shared\Resources\ErrorReports\Pages\ListErrorReports;
use App\Filament\Shared\Resources\ErrorReports\Pages\ViewErrorReport;
use App\Models\ErrorReport;
use App\Models\User;
use App\Support\ErrorTracker;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The errors users run into, kept with a code (App\Support\ErrorTracker) and shown to the
 * administrators under Settings → Error tracking.
 */
class ErrorTrackingTest extends TestCase
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

    public function test_an_error_on_a_page_is_recorded_with_who_and_where_and_its_code_goes_back(): void
    {
        config(['app.debug' => false]);
        Route::middleware('web')->post('/_boom', fn () => throw new RuntimeException('The thing broke'));

        $response = $this->actingAs($this->admin)->post('/_boom', ['note' => 'hello', 'password' => 'never-kept', '_token' => 'x']);

        $report = ErrorReport::query()->sole();
        $response->assertStatus(500)->assertHeader('X-Error-Code', $report->code)->assertSee($report->code);
        $this->assertMatchesRegularExpression('/^E-[A-Z2-9]{6}$/', $report->code);
        $this->assertSame('web', $report->source);
        $this->assertSame(500, $report->status);
        $this->assertSame(RuntimeException::class, $report->exception);
        $this->assertSame('The thing broke', $report->message);
        $this->assertSame($this->admin->id, $report->user_id);
        $this->assertStringContainsString('/_boom', $report->url);
        $this->assertStringContainsString('ErrorTrackingTest.php', $report->file);
        $this->assertNotEmpty($report->trace);
        // What was sent, without its secrets.
        $this->assertSame('hello', $report->input['note']);
        $this->assertSame('***', $report->input['password']);
        $this->assertArrayNotHasKey('_token', $report->input);
    }

    public function test_what_is_not_an_error_is_not_recorded(): void
    {
        $this->actingAs($this->admin)->get('/no-such-page-anywhere')->assertNotFound();

        $this->assertSame(0, ErrorReport::query()->count());
    }

    public function test_the_same_error_in_a_loop_is_kept_a_few_times_not_every_time(): void
    {
        foreach (range(1, 12) as $time) {
            // The same line each time: the same fingerprint.
            ErrorTracker::record($this->sameError());
        }

        $this->assertSame(5, ErrorReport::query()->count());
        $this->assertSame(1, ErrorReport::query()->distinct()->count('fingerprint'));
        $this->assertSame(5, ErrorReport::query()->distinct()->count('code'));
    }

    public function test_the_administrator_finds_it_by_its_code_and_resolves_every_time_it_happened(): void
    {
        ErrorTracker::record($this->sameError());
        ErrorTracker::record($this->sameError());
        ErrorTracker::record(new RuntimeException('Another one'));
        [$first, $second, $other] = ErrorReport::query()->orderBy('id')->get()->all();

        Livewire::actingAs($this->admin)
            ->test(ListErrorReports::class)
            ->assertCanSeeTableRecords([$first, $second, $other])
            ->searchTable($second->code)
            ->assertCanSeeTableRecords([$second])
            ->assertCanNotSeeTableRecords([$first, $other])
            ->callTableAction('resolve', $second);

        $this->assertNotNull($first->fresh()->resolved_at);
        $this->assertSame($this->admin->id, $second->fresh()->resolved_by);
        $this->assertNull($other->fresh()->resolved_at);
        $this->assertSame('1', ErrorReportResource::getNavigationBadge());

        Livewire::actingAs($this->admin)
            ->test(ViewErrorReport::class, ['record' => $second->getKey()])
            ->assertSee($second->code)
            ->assertSee('Boom in a loop')
            ->callAction('reopen');

        $this->assertNull($first->fresh()->resolved_at);
    }

    public function test_the_screen_is_for_whoever_holds_its_permission(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $this->assertFalse(ErrorReportResource::canAccess());

        $user->givePermissionTo(Permission::findOrCreate(ErrorReport::PERMISSION, 'web'));
        $this->assertTrue(ErrorReportResource::canAccess());
    }

    public function test_old_records_are_deleted(): void
    {
        ErrorTracker::record(new RuntimeException('Old'));
        ErrorReport::query()->update(['created_at' => now()->subDays(ErrorReport::KEEP_DAYS + 1)]);
        ErrorTracker::record(new RuntimeException('New'));

        $this->assertSame(1, ErrorReport::prune());
        $this->assertSame('New', ErrorReport::query()->sole()->message);
    }

    private function sameError(): RuntimeException
    {
        return new RuntimeException('Boom in a loop');
    }
}
