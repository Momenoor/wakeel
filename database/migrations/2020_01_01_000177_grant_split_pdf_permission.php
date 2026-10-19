<?php

use App\Support\ScreenPermissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * The Split PDF page: for whoever already adds files to matters (as Sign PDF is) — and the admin role, which
 * has every screen of its kind.
 */
return new class extends Migration
{
    public function up(): void
    {
        ScreenPermissions::grant(ScreenPermissions::SPLIT_PDF);

        $admin = DB::table('roles')->where('name', 'admin')->where('guard_name', 'web')->value('id');
        $id = DB::table('permissions')->where('name', ScreenPermissions::SPLIT_PDF)->where('guard_name', 'web')->value('id');

        if ($admin !== null && $id !== null) {
            DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $id, 'role_id' => $admin]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        DB::table('permissions')->where('name', ScreenPermissions::SPLIT_PDF)->delete();
    }
};
