<?php

namespace Tests\Feature;

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Lab404\Impersonate\Services\ImpersonateManager;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use TomatoPHP\FilamentUsers\Filament\Resources\Users\Pages\ListUsers;

/**
 * Signing in as another user from the users list, and coming back.
 */
class ImpersonationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('mms');
        Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('super-admin');
    }

    public function test_the_button_follows_the_language_chosen(): void
    {
        $other = User::factory()->create();
        $this->actingAs($this->admin);

        app()->setLocale('en');
        Livewire::test(ListUsers::class)
            ->assertTableActionVisible('impersonate', $other)
            ->assertTableActionHasLabel('impersonate', 'Impersonate User', $other);

        app()->setLocale('ar');
        Livewire::test(ListUsers::class)
            ->assertTableActionHasLabel('impersonate', 'تقمص مستخدم', $other);
    }

    public function test_the_plugins_roles_labels_follow_the_language_too(): void
    {
        Filament::bootCurrentPanel();
        $this->actingAs($this->admin);

        app()->setLocale('en');
        $column = Livewire::test(ListUsers::class)->instance()->getTable()->getColumn('roles.name');
        $this->assertSame('Roles', $column->getLabel());

        app()->setLocale('ar');
        $this->assertSame(trans('filament-users::user.resource.roles', [], 'ar'), $column->getLabel());
        $this->assertNotSame('Roles', $column->getLabel());
    }

    public function test_leaving_goes_back_to_the_original_user(): void
    {
        $other = User::factory()->create();
        $this->actingAs($this->admin);

        Livewire::test(ListUsers::class)->callTableAction('impersonate', $other);

        $this->assertTrue(app(ImpersonateManager::class)->isImpersonating());
        $this->assertTrue(auth()->user()->is($other));

        $this->get(route('filament-users.leave'))->assertRedirect();

        $this->assertTrue(auth()->user()->is($this->admin));
        $this->assertFalse(app(ImpersonateManager::class)->isImpersonating());
    }

    public function test_impersonating_needs_its_own_permission_and_never_reaches_a_super_admin(): void
    {
        $staff = User::factory()->create();
        $this->assertFalse($staff->canImpersonate());

        $staff->givePermissionTo(Permission::findOrCreate('Impersonate:User', 'web'));
        $this->assertTrue($staff->fresh()->canImpersonate());

        $this->actingAs($staff);
        $this->assertFalse($this->admin->canBeImpersonated());
        $this->assertTrue(User::factory()->create()->canBeImpersonated());

        $this->actingAs($this->admin);
        $this->assertTrue(User::factory()->create()->assignRole('super-admin')->canBeImpersonated());
    }
}
