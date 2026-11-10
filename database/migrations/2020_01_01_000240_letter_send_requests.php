<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A letter an assistant issued and did not send: asked of their administrator to send — by WhatsApp, email or
 * SMS — with what was done about each way; and the SMS text of a letter template, shown to whoever sends it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('letter_send_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('matter_letter_id')->unique()->constrained('matter_letters')->cascadeOnDelete();
            $table->foreignId('matter_id')->constrained('matters')->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('channels')->comment('The ways asked for: whatsapp, email, sms');
            $table->json('done')->nullable()->comment('Each way done: way => {by, at}');
            $table->text('sms_text')->nullable()->comment('The SMS to copy, when SMS is asked for');
            $table->text('note')->nullable();
            $table->timestamp('completed_at')->nullable()->index()->comment('Once every way asked for is done');
            $table->timestamps();
        });

        Schema::table('letter_templates', function (Blueprint $table) {
            $table->text('sms_template')->nullable()->after('email_template_id')->comment('The SMS that goes with a letter of it, with placeholders');
        });
    }

    public function down(): void
    {
        Schema::table('letter_templates', function (Blueprint $table) {
            $table->dropColumn('sms_template');
        });

        Schema::dropIfExists('letter_send_requests');
    }
};
