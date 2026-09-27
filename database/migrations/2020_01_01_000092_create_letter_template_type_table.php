<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The matter types a letter template is for. A template with none is for
 * every type.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('letter_template_type', function (Blueprint $table) {
            $table->id();
            $table->foreignId('letter_template_id')->constrained('letter_templates')->cascadeOnDelete();
            $table->foreignId('type_id')->constrained('types')->cascadeOnDelete();

            $table->unique(['letter_template_id', 'type_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('letter_template_type');
    }
};
