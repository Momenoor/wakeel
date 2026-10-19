<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The rest of a theme's settings — navigation, sidebar, content width, light/dark mode, backgrounds, cards,
 * headings, logos, the login page — kept together as options (App\Support\ThemeOptions lists them), so a new
 * setting needs no new column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('themes', function (Blueprint $table) {
            $table->json('options')->nullable()->after('sidebar');
        });
    }

    public function down(): void
    {
        Schema::table('themes', function (Blueprint $table) {
            $table->dropColumn('options');
        });
    }
};
