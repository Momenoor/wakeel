<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The filing mailbox (Mail senders): an email someone in the office forwards to it, naming a matter, is filed on
 * that matter as it came from its own sender — and each forward read there, once, with what came of it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mail_senders', function (Blueprint $table) {
            $table->boolean('files_forwards')->default(false)->after('keep_all_inbox')
                ->comment('The filing mailbox: what is forwarded to it, naming a matter, is filed there as its original');
        });

        Schema::create('email_forwards', function (Blueprint $table) {
            $table->id();
            $table->string('sender_key', 100)->comment('the filing mailbox it came to');
            $table->string('message_id', 191)->unique()->comment('the forward\'s own Message-ID');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete()->comment('who forwarded it');
            $table->foreignId('matter_id')->nullable()->constrained('matters')->nullOnDelete();
            $table->foreignId('matter_email_id')->nullable()->constrained('matter_emails')->nullOnDelete()->comment('the original, as filed');
            $table->string('outcome', 40)->comment('filed, or why not');
            $table->string('subject', 500)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_forwards');

        Schema::table('mail_senders', function (Blueprint $table) {
            $table->dropColumn('files_forwards');
        });
    }
};
