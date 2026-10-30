<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A matter type's phases each with a folder structure of their own (made inside a folder of the phase's name);
 * and the emails put in a matter's OneDrive folder by hand (.eml, .msg) read from it — each file looked at once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('types', function (Blueprint $table) {
            $table->json('phase_folders')->nullable()->after('phases');
        });

        Schema::table('matter_onedrive_folders', function (Blueprint $table) {
            $table->timestamp('emails_read_at')->nullable()->after('moved_at');
        });

        Schema::create('matter_onedrive_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('matter_id')->constrained()->cascadeOnDelete();
            $table->string('item_id', 191)->unique();
            $table->string('path', 1000);
            $table->string('outcome', 20);
            $table->foreignId('matter_email_id')->nullable()->constrained('matter_emails')->nullOnDelete();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('matter_onedrive_files');

        Schema::table('matter_onedrive_folders', function (Blueprint $table) {
            $table->dropColumn('emails_read_at');
        });

        Schema::table('types', function (Blueprint $table) {
            $table->dropColumn('phase_folders');
        });
    }
};
