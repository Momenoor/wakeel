<?php

use App\Support\ScreenPermissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The matter page's Bulk mail and Contacts tabs — for whoever can see a matter.
 */
return new class extends Migration
{
    public function up(): void
    {
        ScreenPermissions::grant(ScreenPermissions::MATTER_BULK_MAIL_TAB);
        ScreenPermissions::grant(ScreenPermissions::MATTER_CONTACTS_TAB);
    }

    public function down(): void
    {
        DB::table('permissions')->whereIn('name', [ScreenPermissions::MATTER_BULK_MAIL_TAB, ScreenPermissions::MATTER_CONTACTS_TAB])->delete();
    }
};
