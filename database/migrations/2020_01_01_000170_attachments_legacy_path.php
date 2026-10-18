<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a file was kept on the public disk before `php artisan attachments:move` took it to the private one.
 * Its old address (/storage/<that path>) is still answered — it opens the file for who may see it — so a link
 * saved in an email, a bookmark or a chat keeps working.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attachments', function (Blueprint $table) {
            $table->string('legacy_path')->nullable()->after('disk')->comment('its path on the public disk, before it was moved');
            $table->index('legacy_path');
        });
    }

    public function down(): void
    {
        Schema::table('attachments', function (Blueprint $table) {
            $table->dropIndex(['legacy_path']);
            $table->dropColumn('legacy_path');
        });
    }
};
