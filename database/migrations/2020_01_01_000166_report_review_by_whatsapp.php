<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reports reviewed on WhatsApp: who reviews an assistant's reports (one or
 * more users), and each message sent to a reviewer for a Review Report or
 * Confirm Report request — which button they pressed, and the text and files
 * they are sending for a rejection or a request for changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('party_report_reviewers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('party_id')->constrained('parties')->cascadeOnDelete()->comment('the assistant');
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete()->comment('who reviews their reports');
            $table->timestamps();

            $table->unique(['party_id', 'user_id']);
        });

        Schema::create('report_review_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('matter_request_id')->constrained('matter_requests')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete()->comment('the reviewer');
            $table->string('phone', 30)->comment('their WhatsApp number, as WhatsApp writes it');
            $table->unsignedSmallInteger('round')->default(1)->comment('1, then +1 each time the report is sent again after changes');
            $table->string('message_id')->nullable()->index()->comment('the template message id');
            $table->string('status', 20)->default('sent')->comment('sent | failed | answered | closed (decided by another reviewer)');
            $table->string('decision', 20)->nullable()->comment('approved | rejected | needs_changes');
            $table->string('awaiting', 20)->nullable()->comment('reject | changes: their text and files are being collected');
            $table->json('pending_texts')->nullable();
            $table->json('pending_files')->nullable()->comment('stored paths');
            $table->json('received_message_ids')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('answered_at')->nullable();
            $table->timestamps();

            $table->index(['phone', 'awaiting']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_review_deliveries');
        Schema::dropIfExists('party_report_reviewers');
    }
};
