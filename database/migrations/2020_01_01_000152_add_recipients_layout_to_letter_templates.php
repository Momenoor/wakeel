<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How a template lays out {{recipients}}: the standard lines, or the formal
 * layout — the party with its honorific at the far end, "بوكالة السادة/ …"
 * for the lawyer, and the emails and phones each on one line.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('letter_templates', function (Blueprint $table) {
            $table->string('recipients_layout', 20)->nullable()->comment('formal; empty: the standard lines');
        });
    }

    public function down(): void
    {
        Schema::table('letter_templates', function (Blueprint $table) {
            $table->dropColumn('recipients_layout');
        });
    }
};
