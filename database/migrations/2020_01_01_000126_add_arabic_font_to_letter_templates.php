<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A template's Arabic text in a font of its own, beside the one for its
 * English text (letter_font_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('letter_templates', function (Blueprint $table) {
            $table->foreignId('arabic_font_id')->nullable()->after('letter_font_id')->constrained('letter_fonts')->nullOnDelete()
                ->comment('the font its Arabic text is written in; empty: as the English font, or the standard one');
        });
    }

    public function down(): void
    {
        Schema::table('letter_templates', function (Blueprint $table) {
            $table->dropConstrainedForeignId('arabic_font_id');
        });
    }
};
