<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which of the office's shared mailboxes a user may use (Users → Edit → Work account): send from, and see the
 * unfiled emails of. Unset: all of them — as before, and any added later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('shared_mailboxes')->nullable()->after('shared_senders')->comment('The shared mailboxes (keys) they may use; null: all of them');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('shared_mailboxes');
        });
    }
};
