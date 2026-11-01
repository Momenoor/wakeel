<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who read each matter email (its Emails tab); a type's phase folders kept with the matters in progress when a
 * phase is finished, and the columns its creditors have of their own; a creditor's number, licence or ID and its
 * own columns' values; when a matter's OneDrive folder was last checked for its folders.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('matter_email_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('matter_email_id')->constrained('matter_emails')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('read_at');
            $table->unique(['matter_email_id', 'user_id']);
        });

        Schema::table('types', function (Blueprint $table) {
            $table->text('phase_keep_folders')->nullable()->after('phase_folders')->comment('Folders of a finished phase kept with the matters in progress, one a line, as the phase\'s folders write them');
            $table->json('creditor_columns')->nullable()->after('has_creditors')->comment('The columns its matters\' creditors have besides the usual: names, one a column');
        });

        Schema::table('matter_creditors', function (Blueprint $table) {
            $table->unsignedInteger('number')->nullable()->after('matter_id')->comment('Its number in the matter\'s list: its claim\'s serial');
            $table->string('id_number', 100)->nullable()->after('name')->comment('Its trade licence or ID number — the same number twice in a matter is one creditor twice');
            $table->json('custom')->nullable()->after('claim_amount')->comment('The values of the columns of its own (Type.creditor_columns)');
            $table->index(['matter_id', 'number']);
        });

        Schema::table('matter_onedrive_folders', function (Blueprint $table) {
            $table->timestamp('checked_at')->nullable()->after('emails_read_at')->comment('When its folders were last looked for (OneDriveFolderCheck)');
        });
    }

    public function down(): void
    {
        Schema::table('matter_onedrive_folders', function (Blueprint $table) {
            $table->dropColumn('checked_at');
        });

        Schema::table('matter_creditors', function (Blueprint $table) {
            $table->dropIndex(['matter_id', 'number']);
            $table->dropColumn(['number', 'id_number', 'custom']);
        });

        Schema::table('types', function (Blueprint $table) {
            $table->dropColumn(['phase_keep_folders', 'creditor_columns']);
        });

        Schema::dropIfExists('matter_email_reads');
    }
};
