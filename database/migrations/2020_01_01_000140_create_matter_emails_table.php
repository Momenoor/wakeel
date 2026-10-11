<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A matter's email, each way: those sent from it (a letter, minutes, bulk
 * mail) — what a reply is matched against — and the replies collected from
 * the sending mailbox's inbox, under the email they answer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('matter_emails', function (Blueprint $table) {
            $table->id();
            $table->foreignId('matter_id')->constrained('matters')->cascadeOnDelete();
            $table->string('direction', 10)->comment('sent | received');
            $table->foreignId('parent_id')->nullable()->constrained('matter_emails')->cascadeOnDelete()->comment('received: the email it answers');
            $table->nullableMorphs('source', 'matter_emails_source_index');
            $table->string('sender_key', 100)->comment('the mailbox it went from, or came to');
            $table->string('message_id', 500)->nullable()->index();
            $table->string('subject', 500)->default('');
            $table->string('from', 255)->nullable();
            $table->json('to')->nullable();
            $table->dateTime('at');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['sender_key', 'direction', 'at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('matter_emails');
    }
};
