<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether an email that came in was unread in its mailbox before Wakeel
 * collected it (and marked it read) — so undoing the collection puts it back
 * as it was. Null: not known (collected before this was kept).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('matter_emails', function (Blueprint $table) {
            $table->boolean('was_unread')->nullable()->comment('unread in the mailbox before it was collected; null: not known');
        });
    }

    public function down(): void
    {
        Schema::table('matter_emails', function (Blueprint $table) {
            $table->dropColumn('was_unread');
        });
    }
};
