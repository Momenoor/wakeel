<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The OneDrive folder review (Settings → OneDrive folder review): for each
 * active matter and each of its assistants with OneDrive, what the scan
 * found in their OneDrive and what was decided — rename and link the
 * folder found, create one, or leave it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('onedrive_folder_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('matter_id')->constrained('matters')->cascadeOnDelete();
            $table->foreignId('party_id')->constrained('parties')->cascadeOnDelete();
            // found, multiple, missing, standard, done, skipped, failed
            $table->string('status')->index();
            // The folders found: [{id, name, webUrl}]
            $table->json('candidates')->nullable();
            $table->string('standard_name');
            $table->string('drive_item_id')->nullable();
            $table->text('web_url')->nullable();
            $table->text('error')->nullable();
            // What was done: the renames and folders made.
            $table->json('log')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('scanned_at')->nullable();
            $table->timestamps();

            $table->unique(['matter_id', 'party_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('onedrive_folder_reviews');
    }
};
