<?php

use App\Support\ScreenPermissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Everyone's leave and employee requests are seen only by a role given to see them all ("View all
 * requests") — the super admin's and the accountant's to begin with, changed in Roles. Everyone else,
 * whoever approves or pays them too, sees their own.
 */
return new class extends Migration
{
    private const ROLES = ['super-admin', 'accountant'];

    public function up(): void
    {
        $roles = DB::table('roles')->whereIn('name', self::ROLES)->where('guard_name', 'web')->pluck('id');

        foreach (['ViewAll:EmployeeRequest', 'ViewAll:LeaveRequest'] as $permission) {
            ScreenPermissions::grant($permission, []);
            $id = DB::table('permissions')->where('name', $permission)->where('guard_name', 'web')->value('id');

            // Those roles only (run again, it is put right).
            DB::table('role_has_permissions')->where('permission_id', $id)->whereNotIn('role_id', $roles)->delete();

            foreach ($roles as $roleId) {
                DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $id, 'role_id' => $roleId]);
            }
        }

        app()['cache']->forget(config('permission.cache.key', 'spatie.permission.cache'));
    }

    public function down(): void
    {
        //
    }
};
