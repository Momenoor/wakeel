<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A letter or minutes made again from their template's current wording and format (its data kept): what it
 * was before, so it can be put back (App\Services\MMS\Letters\DocumentRegenerator).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_regenerations', function (Blueprint $table) {
            $table->id();
            $table->morphs('document', 'doc_regen_document_index');
            $table->foreignId('letter_template_id')->nullable()->constrained('letter_templates')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            // body, subject, rendered_html, letterhead_id, attachment_id — as they were.
            $table->json('previous');
            $table->dateTime('undone_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_regenerations');
    }
};
