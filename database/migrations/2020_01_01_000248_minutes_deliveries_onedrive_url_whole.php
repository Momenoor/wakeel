<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A signed copy's link in OneDrive kept whole: the column took 255 characters, and a link through Arabic folder
 * names (each letter six characters in a web address) is longer — it was cut, and opened "Bad Request - Invalid
 * URL". Those cut already are found again by `php artisan minutes:relink-signed-copies`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('minutes_deliveries', function (Blueprint $table) {
            $table->text('onedrive_url')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Left as text: making it short again would cut the links.
    }
};
