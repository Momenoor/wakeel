<?php

namespace Tests\Feature;

use App\Filament\Shared\Users\OwnPermissions;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use TomatoPHP\FilamentUsers\Filament\Resources\Users\Pages\EditUser;
use TomatoPHP\FilamentUsers\Filament\Resources\Users\Pages\ViewUser;

/**
 * Permissions of a user's own, beside their roles', given on their page.
 */
class OwnPermissionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('mms'));
        foreach (['View:Matter', 'ViewAny:Matter', 'Update:Matter', 'View:Party', 'View:MatterContactsTab', 'Update:Role'] as $name) {
            Permission::findOrCreate($name);
        }
        $this->actingAs(User::factory()->create()->assignRole(Role::firstOrCreate(['name' => config('filament-shield.super_admin.name', 'super_admin'), 'guard_name' => 'web'])));
    }

    public function test_the_permissions_are_grouped_by_what_they_are_on_and_named_in_words(): void
    {
        $groups = OwnPermissions::groups();

        $this->assertSame(['Update:Matter' => 'Update', 'View:Matter' => 'View', 'ViewAny:Matter' => 'View Any'], array_intersect_key($groups['Matter'], array_flip(['View:Matter', 'ViewAny:Matter', 'Update:Matter'])));
        $this->assertArrayHasKey('View:Party', $groups['Party']);
        $this->assertArrayHasKey('View:MatterContactsTab', $groups['MatterContactsTab']);
        $this->assertSame('Matter: View', OwnPermissions::describe('View:Matter'));
        $this->assertSame('Matter Contacts Tab: View', OwnPermissions::describe('View:MatterContactsTab'));
    }

    public function test_the_permissions_ticked_on_a_users_page_are_theirs_beside_their_roles(): void
    {
        $role = Role::create(['name' => 'clerk', 'guard_name' => 'web']);
        $role->givePermissionTo('View:Party');
        $user = User::factory()->create(['name' => 'Huda'])->assignRole($role);

        // Given: theirs, the role's kept; one of the role's ticked too is theirs of their own as well.
        Livewire::test(EditUser::class, ['record' => $user->getKey()])
            ->assertSchemaStateSet(['own_permissions.matter' => []])
            ->fillForm(['display_name' => 'Huda', 'own_permissions.matter' => ['View:Matter', 'Update:Matter'], 'own_permissions.party' => ['View:Party']])
            ->call('save')
            ->assertHasNoFormErrors();
        $user = $user->fresh();
        $this->assertEqualsCanonicalizing(['View:Matter', 'Update:Matter', 'View:Party'], $user->getDirectPermissions()->pluck('name')->all());
        $this->assertTrue($user->can('Update:Matter'));
        $this->assertTrue($user->can('View:Party'));

        // Shown on their page in words; unticked: taken back, the role's still theirs through the role.
        Livewire::test(ViewUser::class, ['record' => $user->getKey()])->assertSee('Matter: Update');
        Livewire::test(EditUser::class, ['record' => $user->getKey()])
            ->assertSchemaStateSet(['own_permissions.matter' => ['Update:Matter', 'View:Matter']])
            ->fillForm(['display_name' => 'Huda', 'own_permissions.matter' => ['View:Matter'], 'own_permissions.party' => []])
            ->call('save')
            ->assertHasNoFormErrors();
        $user = $user->fresh();
        $this->assertSame(['View:Matter'], $user->getDirectPermissions()->pluck('name')->all());
        $this->assertFalse($user->can('Update:Matter'));
        $this->assertTrue($user->can('View:Party'));

        // Only whoever may edit roles sees the section.
        $this->assertTrue(OwnPermissions::section()->isVisible());
        $this->actingAs(User::factory()->create());
        $this->assertFalse(OwnPermissions::section()->isVisible());
    }
}
