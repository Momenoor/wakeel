<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "A fee or a collection was added, changed or deleted": in the bell for super admins and accountants to
 * begin with (each can turn it off; any other role can be given it under Notification settings).
 */
return new class extends Migration
{
    public function up(): void
    {
        $roles = DB::table('roles')
            ->whereIn('name', [config('filament-shield.super_admin.name', 'super_admin'), 'accountant'])
            ->where('guard_name', 'web')
            ->pluck('id');

        DB::table('role_notification_defaults')->insertOrIgnore($roles->map(fn ($role): array => [
            'role_id' => $role, 'type' => 'fees.changed', 'channel' => 'bell', 'state' => 'on', 'created_at' => now(), 'updated_at' => now(),
        ])->all());
    }

    public function down(): void
    {
        DB::table('role_notification_defaults')->where('type', 'fees.changed')->delete();
    }
};
