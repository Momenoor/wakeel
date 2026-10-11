<?php

use App\Support\ScreenPermissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * System Settings' Letters & emails tab — for whoever can open System
 * Settings.
 */
return new class extends Migration
{
    public function up(): void
    {
        ScreenPermissions::grant(ScreenPermissions::SETTINGS_LETTERS_TAB);
    }

    public function down(): void
    {
        DB::table('permissions')->where('name', ScreenPermissions::SETTINGS_LETTERS_TAB)->delete();
    }
};
