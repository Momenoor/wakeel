<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Who hears of a new leave request is now a setting (System Settings →
 * Notifications); until now it was four fixed addresses of the original
 * office. That office keeps them — any other starts with whoever can
 * approve leave.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! str_contains((string) config('app.url'), 'jpaemirates.com')
            || DB::table('settings')->where('key', 'leave_request_recipients')->exists()) {
            return;
        }

        DB::table('settings')->insert([
            'key' => 'leave_request_recipients',
            'value' => json_encode(['redha@jpaemirates.com', 'expert@jpaemirates.com', 'momen.noor@jpaemirates.com', 'info@jpaemirates.com']),
            'group' => 'notifications',
            'type' => 'array',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Setting::clearCache();
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'leave_request_recipients')->delete();
    }
};
