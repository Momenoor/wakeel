<?php

use App\Support\ScreenPermissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The Parties list's External entities tab — for whoever can list parties.
 */
return new class extends Migration
{
    public function up(): void
    {
        ScreenPermissions::grant(ScreenPermissions::PARTIES_EXTERNAL_TAB);
    }

    public function down(): void
    {
        DB::table('permissions')->where('name', ScreenPermissions::PARTIES_EXTERNAL_TAB)->delete();
    }
};
