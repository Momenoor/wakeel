<?php

namespace Tests\Feature;

use App\Models\CalendarEvent;
use App\Models\Party;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * MySQL takes index and key names of at most 64 characters; the tests run on SQLite, which takes any — a name
 * Laravel made too long passed here and stopped the update on the server (the calendar alarms' unique key).
 */
class MigrationNamesTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_index_and_foreign_key_name_fits_mysql(): void
    {
        $tooLong = [];

        foreach (Schema::getTableListing() as $table) {
            $table = str_contains($table, '.') ? substr($table, strrpos($table, '.') + 1) : $table;

            foreach (Schema::getIndexes($table) as $index) {
                if (strlen((string) $index['name']) > 64) {
                    $tooLong[] = $table.': '.$index['name'];
                }
            }

            foreach (Schema::getForeignKeys($table) as $key) {
                // Laravel's own name for it on MySQL: table_column_foreign.
                $name = $key['name'] ?: $table.'_'.implode('_', $key['columns']).'_foreign';
                if (strlen($name) > 64) {
                    $tooLong[] = $table.': '.$name;
                }
            }
        }

        $this->assertSame([], $tooLong, "Longer than MySQL's 64 characters:\n".implode("\n", $tooLong));
    }

    public function test_the_calendar_alarms_table_left_half_made_on_mysql_is_finished_keeping_its_rows(): void
    {
        // As the first run on MySQL left it: the table and its rows, but no unique key nor alarm index — and
        // two rows for one alarm, written while there was no key.
        Schema::drop('calendar_event_alerts');
        Schema::create('calendar_event_alerts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('calendar_event_id');
            $table->unsignedBigInteger('party_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedSmallInteger('minutes_before');
            $table->dateTime('alarm_at');
            $table->string('mailbox')->nullable();
            $table->string('outlook_item_id', 512)->nullable();
            $table->dateTime('notified_at')->nullable();
            $table->dateTime('removed_at')->nullable();
            $table->text('error')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->dateTime('retry_at')->nullable();
            $table->dateTime('gave_up_at')->nullable();
            $table->timestamps();
        });
        $event = CalendarEvent::create(['title' => 'Visit', 'start_datetime' => '2026-11-01 10:00:00', 'type' => 'single'])->id;
        $party = Party::factory()->create()->id;
        DB::table('calendar_event_alerts')->insert([
            ['calendar_event_id' => $event, 'party_id' => $party, 'minutes_before' => 60, 'alarm_at' => '2026-11-01 09:00:00', 'outlook_item_id' => 'item-1'],
            ['calendar_event_id' => $event, 'party_id' => $party, 'minutes_before' => 60, 'alarm_at' => '2026-11-01 09:00:00', 'outlook_item_id' => 'item-2'],
            ['calendar_event_id' => $event, 'party_id' => $party, 'minutes_before' => 1440, 'alarm_at' => '2026-10-31 10:00:00', 'outlook_item_id' => 'item-3'],
            // Its event deleted while there was no key: it goes.
            ['calendar_event_id' => 999, 'party_id' => $party, 'minutes_before' => 60, 'alarm_at' => '2026-11-01 09:00:00', 'outlook_item_id' => 'item-4'],
        ]);

        (require database_path('migrations/2020_01_01_000176_create_calendar_event_alerts_table.php'))->up();

        $this->assertTrue(Schema::hasIndex('calendar_event_alerts', 'cal_alerts_event_party_minutes_unique'));
        $this->assertTrue(Schema::hasIndex('calendar_event_alerts', 'cal_alerts_alarm_at_index'));
        // The rows kept — the first of the two.
        $this->assertSame(['item-1', 'item-3'], DB::table('calendar_event_alerts')->orderBy('id')->pluck('outlook_item_id')->all());

        // Again: nothing to do.
        (require database_path('migrations/2020_01_01_000176_create_calendar_event_alerts_table.php'))->up();
        $this->assertSame(2, DB::table('calendar_event_alerts')->count());
    }
}
