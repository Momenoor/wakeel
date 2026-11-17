<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Voice calls in the chat: one between the two people of a one-to-one conversation, made in their browsers
 * (WebRTC) — what each browser offered and answered kept here for the other to read, how it went (ringing,
 * answered, ended, missed, declined, failed), when it was answered and ended; and the line it leaves in the
 * conversation (chat_messages.chat_call_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chat_conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('caller_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('callee_id')->constrained('users')->cascadeOnDelete();
            $table->string('status', 20)->default('ringing');
            $table->longText('offer')->nullable();
            $table->longText('answer')->nullable();
            $table->timestamp('answered_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'updated_at']);
        });

        Schema::table('chat_messages', function (Blueprint $table) {
            $table->foreignId('chat_call_id')->nullable()->after('reply_to_id')->constrained('chat_calls')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('chat_call_id');
        });

        Schema::dropIfExists('chat_calls');
    }
};
