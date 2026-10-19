<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A calendar event's two alarms for each person it concerns (its matter's assistant and expert): 24 hours and
 * 1 hour before. Each alarm is a short reminder item put in the person's own Outlook calendar — so it rings in
 * Outlook, Teams and the phone, which allow one alarm per event — and taken away once it has rung; and a
 * system notification (bell, email, WhatsApp, as the person chose). App\Services\MMS\Calendar\CalendarAlarms.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendar_event_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('calendar_event_id')->constrained('calendar_events')->cascadeOnDelete();
            $table->foreignId('party_id')->nullable()->constrained('parties')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedSmallInteger('minutes_before')->comment('1440 (a day) or 60');
            $table->dateTime('alarm_at')->comment('when it rings: the event\'s start less minutes_before');
            $table->string('mailbox')->nullable()->comment('the Outlook mailbox the reminder item is in');
            $table->string('outlook_item_id', 512)->nullable()->comment('the reminder item in that mailbox');
            $table->dateTime('notified_at')->nullable()->comment('the system notification sent');
            $table->dateTime('removed_at')->nullable()->comment('the reminder item taken away after it rang');
            $table->text('error')->nullable();
            // A mailbox that refuses is tried again later and later, then left (the error kept).
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->dateTime('retry_at')->nullable();
            $table->dateTime('gave_up_at')->nullable();
            $table->timestamps();

            $table->unique(['calendar_event_id', 'party_id', 'minutes_before']);
            $table->index('alarm_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_event_alerts');
    }
};
