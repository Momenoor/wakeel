<?php

use App\Support\ScreenPermissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Signing PDFs: the Sign PDF page and the office's signatures and stamps, for whoever already adds files to
 * matters (and, managing the shared ones, whoever looks after the letterheads) — and the admin role, which
 * has every screen of its kind.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        ScreenPermissions::SIGN_PDF,
        ScreenPermissions::SHARED_SIGNATURES,
        ScreenPermissions::MANAGE_SHARED_SIGNATURES,
    ];

    public function up(): void
    {
        foreach (self::PERMISSIONS as $permission) {
            ScreenPermissions::grant($permission);
        }

        $admin = DB::table('roles')->where('name', 'admin')->where('guard_name', 'web')->value('id');

        if ($admin !== null) {
            foreach (DB::table('permissions')->whereIn('name', self::PERMISSIONS)->where('guard_name', 'web')->pluck('id') as $id) {
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
