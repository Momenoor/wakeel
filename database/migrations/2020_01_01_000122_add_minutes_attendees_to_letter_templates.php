<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How a minutes template lays out {{minutes.attendees}}: grouped under each
 * capacity, one numbered list, or a table — and the wording of each line.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('letter_templates', function (Blueprint $table) {
            $table->json('minutes_attendees')->nullable()->comment('{layout, line, heading, columns}; empty: the standard layout');
        });
    }

    public function down(): void
    {
        Schema::table('letter_templates', function (Blueprint $table) {
            $table->dropColumn('minutes_attendees');
        });
    }
};
