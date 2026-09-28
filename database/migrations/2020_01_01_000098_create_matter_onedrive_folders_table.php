<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A folder for each matter in its assistant's OneDrive, created with the
 * office's standard structure when the assistant is assigned.
 *
 * - parties: the assistant's Microsoft 365 account and the folder in their
 *   OneDrive where matter folders go.
 * - matter_onedrive_folders: one row per matter and assistant — whether the
 *   folder was made, its link, and why not when it failed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parties', function (Blueprint $table) {
            $table->string('onedrive_email')->nullable();
            $table->string('onedrive_path')->nullable();
        });

        Schema::create('matter_onedrive_folders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('matter_id')->constrained('matters')->cascadeOnDelete();
            $table->foreignId('party_id')->constrained('parties')->cascadeOnDelete();
            $table->string('folder_name');
            $table->string('status')->default('pending');
            $table->string('drive_item_id')->nullable();
            $table->text('web_url')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->unique(['matter_id', 'party_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('matter_onedrive_folders');

        Schema::table('parties', function (Blueprint $table) {
            $table->dropColumn(['onedrive_email', 'onedrive_path']);
        });
    }
};
