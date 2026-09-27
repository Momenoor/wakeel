<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Emailing letters: reusable email templates (the covering email a letter
 * is attached to), and what a letter's sending recorded — the mailbox it
 * went from, and why a recipient's delivery failed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('locale')->default('ar');
            $table->string('subject');
            $table->longText('body');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        Schema::table('matter_letters', function (Blueprint $table) {
            $table->string('sender_key')->nullable()->after('sent_by');
        });

        Schema::table('matter_letter_recipients', function (Blueprint $table) {
            $table->text('failure_reason')->nullable()->after('delivered_at');
        });
    }

    public function down(): void
    {
        Schema::table('matter_letter_recipients', function (Blueprint $table) {
            $table->dropColumn('failure_reason');
        });

        Schema::table('matter_letters', function (Blueprint $table) {
            $table->dropColumn('sender_key');
        });

        Schema::dropIfExists('email_templates');
    }
};
