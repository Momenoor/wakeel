<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The "admin" role sees the matters of the assistants its users administer (Assistants → Administrators of their
 * matters) and no others: "View administered assistants' matters only" in place of viewing any matter. A user of
 * the role with no assistant to administer sees none. Set again on the Roles screen if the office wants otherwise.
 */
return new class extends Migration
{
    public function up(): void
    {
        $role = Role::query()->where('name', 'admin')->first();

        if ($role === null) {
            return;
        }

        $role->givePermissionTo(Permission::findOrCreate('ViewAdministered:Matter', $role->guard_name));
        $role->revokePermissionTo(Permission::query()->whereIn('name', ['ViewAny:Matter', 'View:Matter'])->where('guard_name', $role->guard_name)->get());
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $role = Role::query()->where('name', 'admin')->first();

        if ($role === null) {
            return;
        }

        $role->givePermissionTo(Permission::findOrCreate('ViewAny:Matter', $role->guard_name), Permission::findOrCreate('View:Matter', $role->guard_name));
        $role->revokePermissionTo(Permission::query()->where('name', 'ViewAdministered:Matter')->where('guard_name', $role->guard_name)->get());
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
