<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The external entities (police, central bank …) a letter template is
 * written to: picking the entity on a letter picks its template.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('letter_template_party', function (Blueprint $table) {
            $table->id();
            $table->foreignId('letter_template_id')->constrained('letter_templates')->cascadeOnDelete();
            $table->foreignId('party_id')->constrained('parties')->cascadeOnDelete();
            $table->unique(['letter_template_id', 'party_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('letter_template_party');
    }
};
