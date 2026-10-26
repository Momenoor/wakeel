<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * The Marketing commissions report (and marking a third party's paid there): for whoever already reads the
 * Deductions Reconciliation report, and the admin role.
 */
return new class extends Migration
{
    private const PERMISSION = 'View:MarketingCommissionsReport';

    public function up(): void
    {
        DB::table('permissions')->insertOrIgnore(['name' => self::PERMISSION, 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now()]);
        $id = DB::table('permissions')->where('name', self::PERMISSION)->where('guard_name', 'web')->value('id');

        $roles = DB::table('role_has_permissions')
            ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
            ->where('permissions.name', 'View:DeductionsReconciliationReport')
            ->pluck('role_has_permissions.role_id')
            ->merge(DB::table('roles')->where('name', 'admin')->where('guard_name', 'web')->pluck('id'))
            ->unique();

        foreach ($roles as $role) {
            DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $id, 'role_id' => $role]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        DB::table('permissions')->where('name', self::PERMISSION)->delete();
    }
};
