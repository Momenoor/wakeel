<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Themes: how the system looks — its colours, fonts, corners, spacing and sidebar — kept as data, so the office
 * can make its own and each user can choose one (Settings → Appearance). The look is applied when a page is
 * drawn: nothing is rebuilt.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('themes', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // {"primary": "#2563eb", "gray": "#64748b", "success": …} — any left out keeps Filament's own.
            $table->json('colors')->nullable();
            $table->foreignId('latin_font_id')->nullable()->constrained('letter_fonts')->nullOnDelete();
            $table->foreignId('arabic_font_id')->nullable()->constrained('letter_fonts')->nullOnDelete();
            $table->string('radius')->default('md')->comment('App\Enums\ThemeRadius');
            $table->string('density')->default('normal')->comment('App\Enums\ThemeDensity');
            $table->string('sidebar')->default('plain')->comment('App\Enums\ThemeSidebar');
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            // The theme the user chose for themselves; none: the office's.
            $table->foreignId('theme_id')->nullable()->constrained('themes')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('theme_id');
        });

        Schema::dropIfExists('themes');
    }
};
