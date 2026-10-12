<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The courts a letter template is for — beside its matter types. A
 * template with none is for every court.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('letter_template_court', function (Blueprint $table) {
            $table->id();
            $table->foreignId('letter_template_id')->constrained('letter_templates')->cascadeOnDelete();
            $table->foreignId('court_id')->constrained('courts')->cascadeOnDelete();

            $table->unique(['letter_template_id', 'court_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('letter_template_court');
    }
};
