<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Impersonating a user is now its own permission (User::canImpersonate()).
 * It used to come with seeing the users list, so whoever — role or user —
 * holds ViewAny:User keeps it.
 */
return new class extends Migration
{
    public function up(): void
    {
        $id = DB::table('permissions')->where('name', 'Impersonate:User')->where('guard_name', 'web')->value('id')
            ?? DB::table('permissions')->insertGetId(['name' => 'Impersonate:User', 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now()]);

        $from = DB::table('permissions')->where('name', 'ViewAny:User')->pluck('id');

        DB::table('role_has_permissions')->whereIn('permission_id', $from)->distinct()->pluck('role_id')
            ->each(fn ($roleId) => DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $id, 'role_id' => $roleId]));

        DB::table('model_has_permissions')->whereIn('permission_id', $from)->select(['model_type', 'model_id'])->distinct()->get()
            ->each(fn ($row) => DB::table('model_has_permissions')->insertOrIgnore(['permission_id' => $id, 'model_type' => $row->model_type, 'model_id' => $row->model_id]));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        //
    }
};
