<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The SMTP password (Settings → Mail server) is kept encrypted, as the integrations' secrets are — it was
 * kept as typed, and sent back to the page. The one saved is encrypted now.
 */
return new class extends Migration
{
    public function up(): void
    {
        $value = DB::table('settings')->where('key', 'mail_password')->value('value');

        if (blank($value)) {
            return;
        }

        try {
            decrypt($value);

            return; // Already encrypted.
        } catch (Throwable) {
            DB::table('settings')->where('key', 'mail_password')->update(['value' => encrypt($value), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        //
    }
};
