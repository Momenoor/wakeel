<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A calendar event's two alarms for each person it concerns (its matter's assistant and expert): 24 hours and
 * 1 hour before. Each alarm is a short reminder item put in the person's own Outlook calendar — so it rings in
 * Outlook, Teams and the phone, which allow one alarm per event — and taken away once it has rung; and a
 * system notification (bell, email, WhatsApp, as the person chose). App\Services\MMS\Calendar\CalendarAlarms.
 *
 * The indexes are named: MySQL takes at most 64 characters, and the names Laravel makes from these columns
 * were longer — on MySQL the first run made the table, then stopped at its unique key. Run again, it finishes
 * that table (keeping what the alarms already wrote in it: their reminder items must still be taken away).
 */
return new class extends Migration
{
    private const UNIQUE = 'cal_alerts_event_party_minutes_unique';

    private const ALARM_INDEX = 'cal_alerts_alarm_at_index';

    public function up(): void
    {
        if (! Schema::hasTable('calendar_event_alerts')) {
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
            });
        }

        $this->finish();
    }

    /** The keys a first run on MySQL did not reach: each added only when missing. */
    private function finish(): void
    {
        $foreign = collect(Schema::getForeignKeys('calendar_event_alerts'))->flatMap(fn (array $key): array => $key['columns'])->all();

        // Written while the keys were missing: a row whose event is gone goes too; one whose person or user is
        // gone keeps its row without them.
        if (! in_array('calendar_event_id', $foreign, true)) {
            DB::table('calendar_event_alerts')->whereNotIn('calendar_event_id', DB::table('calendar_events')->select('id'))->delete();
        }

        if (! in_array('party_id', $foreign, true)) {
            DB::table('calendar_event_alerts')->whereNotNull('party_id')->whereNotIn('party_id', DB::table('parties')->select('id'))->update(['party_id' => null]);
        }

        if (! in_array('user_id', $foreign, true)) {
            DB::table('calendar_event_alerts')->whereNotNull('user_id')->whereNotIn('user_id', DB::table('users')->select('id'))->update(['user_id' => null]);
        }

        Schema::table('calendar_event_alerts', function (Blueprint $table) use ($foreign) {
            if (! in_array('calendar_event_id', $foreign, true)) {
                $table->foreign('calendar_event_id')->references('id')->on('calendar_events')->cascadeOnDelete();
            }

            if (! in_array('party_id', $foreign, true)) {
                $table->foreign('party_id')->references('id')->on('parties')->nullOnDelete();
            }

            if (! in_array('user_id', $foreign, true)) {
                $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            }
        });

        if (! Schema::hasIndex('calendar_event_alerts', self::UNIQUE)) {
            // Two rows for one alarm (written before the key was there): the first kept.
            $keep = DB::table('calendar_event_alerts')
                ->selectRaw('MIN(id) as id')
                ->groupBy('calendar_event_id', 'party_id', 'minutes_before')
                ->pluck('id');
            DB::table('calendar_event_alerts')->whereNotIn('id', $keep->all() ?: [0])->delete();

            Schema::table('calendar_event_alerts', function (Blueprint $table) {
                $table->unique(['calendar_event_id', 'party_id', 'minutes_before'], self::UNIQUE);
            });
        }

        if (! Schema::hasIndex('calendar_event_alerts', self::ALARM_INDEX)) {
            Schema::table('calendar_event_alerts', function (Blueprint $table) {
                $table->index('alarm_at', self::ALARM_INDEX);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_event_alerts');
    }
};
