<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * A matter's difficulty (it weighs on the incentives) and whether it is office work: set when it is made, changed
 * after only with a permission of their own — not whoever may edit the matter. The admin role has them; any other
 * role is given them from Roles.
 */
return new class extends Migration
{
    private const PERMISSIONS = ['UpdateDifficulty:Matter', 'UpdateOfficeWork:Matter'];

    public function up(): void
    {
        $admin = DB::table('roles')->where('name', 'admin')->where('guard_name', 'web')->value('id');

        foreach (self::PERMISSIONS as $permission) {
            DB::table('permissions')->insertOrIgnore(['name' => $permission, 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now()]);
            $id = DB::table('permissions')->where('name', $permission)->where('guard_name', 'web')->value('id');

            if ($admin) {
                DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $id, 'role_id' => $admin]);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        DB::table('permissions')->whereIn('name', self::PERMISSIONS)->delete();
    }
};
