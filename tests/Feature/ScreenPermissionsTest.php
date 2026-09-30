<?php

namespace Tests\Feature;

use App\Filament\Mms\Pages\Chat;
use App\Filament\Mms\Resources\Courts\Pages\EditCourt;
use App\Filament\Mms\Resources\Courts\RelationManagers\MattersRelationManager;
use App\Filament\Mms\Resources\EmailTemplates\EmailTemplateResource;
use App\Filament\Mms\Resources\Matters\Pages\ListMatters;
use App\Filament\Shared\ActivityLog\AuditDashboard;
use App\Models\Court;
use App\Models\User;
use App\Support\ScreenPermissions;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Relation managers, tabs and pages each answer to their own permission,
 * and the migration that introduced them kept everyone's existing access.
 */
class ScreenPermissionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('mms');
    }

    /**
     * @param  list<string>  $permissions
     */
    private function userWith(array $permissions): User
    {
        $user = User::factory()->create();

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $user->givePermissionTo($permissions);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }

    public function test_a_relation_manager_needs_its_own_permission(): void
    {
        $court = Court::factory()->create();

        $this->actingAs($this->userWith(['View:Court', 'ViewAny:Matter']));
        $this->assertFalse(MattersRelationManager::canViewForRecord($court, EditCourt::class));

        $this->actingAs($this->userWith(['View:Court', 'ViewAny:Matter', ScreenPermissions::COURT_MATTERS]));
        $this->assertTrue(MattersRelationManager::canViewForRecord($court, EditCourt::class));
    }

    public function test_list_tabs_follow_their_own_permissions(): void
    {
        $this->actingAs($this->userWith(['ViewAny:Matter', ScreenPermissions::MATTERS_ALL_TAB, ScreenPermissions::MATTERS_FINAL_TAB]));

        $page = Livewire::test(ListMatters::class)->instance();

        $this->assertSame(['all', 'final_submitted'], array_keys($page->getCachedTabs()));
        // In Progress is not theirs, so they land on the first tab they have.
        $this->assertSame('all', $page->getDefaultActiveTab());
    }

    public function test_resources_that_had_no_policy_now_need_permission(): void
    {
        $this->actingAs($this->userWith([]));
        $this->assertFalse(EmailTemplateResource::canViewAny());

        $this->actingAs($this->userWith(['ViewAny:EmailTemplate']));
        $this->assertTrue(EmailTemplateResource::canViewAny());
    }

    public function test_chat_and_the_audit_dashboard_need_permission(): void
    {
        $this->actingAs($this->userWith([]));
        $this->assertFalse(Chat::canAccess());
        $this->assertFalse(AuditDashboard::canAccess());

        $this->actingAs($this->userWith(['View:Chat', 'View:AuditDashboard']));
        $this->assertTrue(Chat::canAccess());
        $this->assertTrue(AuditDashboard::canAccess());
    }

    public function test_the_migration_grants_new_permissions_to_those_who_could_already_see(): void
    {
        $staff = Role::create(['name' => 'staff', 'guard_name' => 'web']);
        $staff->givePermissionTo(Permission::findOrCreate('View:Matter', 'web'), Permission::findOrCreate('View:PayrollRun', 'web'));

        $finance = Role::create(['name' => 'finance', 'guard_name' => 'web']);
        $finance->givePermissionTo(Permission::findOrCreate('View:FinancialConfiguration', 'web'), Permission::findOrCreate('ViewAny:PayrollRun', 'web'));

        (require database_path('migrations/2020_01_01_000104_grant_screen_permissions.php'))->up();

        $staff->refresh();
        $finance->refresh();

        $this->assertTrue($staff->hasPermissionTo(ScreenPermissions::MATTER_FEES_TAB));
        $this->assertTrue($staff->hasPermissionTo(ScreenPermissions::MATTER_LETTERS));
        $this->assertTrue($staff->hasPermissionTo(ScreenPermissions::EMPLOYEE_LEAVE_BALANCE));
        $this->assertTrue($staff->hasPermissionTo('View:FlightTickets'));
        $this->assertTrue($staff->hasPermissionTo('View:Chat'));
        $this->assertFalse($staff->hasPermissionTo(ScreenPermissions::COURT_MATTERS));

        // Payroll-only finance role: the Payroll tab, not the Incentive one.
        $this->assertTrue($finance->hasPermissionTo(ScreenPermissions::FINANCIAL_PAYROLL_TAB));
        $this->assertFalse($finance->hasPermissionTo(ScreenPermissions::FINANCIAL_INCENTIVE_TAB));
    }
}
