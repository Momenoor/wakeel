<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mailboxes letters and bulk mail can be sent from, managed in the app —
 * a cPanel mailbox (SMTP) or a Microsoft 365 one (Microsoft Graph). The
 * senders in config/mail_senders.php still work alongside these.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_senders', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->string('address');
            $table->string('driver')->default('smtp'); // smtp | microsoft
            $table->string('host')->nullable();
            $table->unsignedSmallInteger('port')->nullable();
            $table->string('encryption')->nullable();
            $table->string('username')->nullable();
            $table->text('password')->nullable(); // encrypted
            $table->longText('signature')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_senders');
    }
};
