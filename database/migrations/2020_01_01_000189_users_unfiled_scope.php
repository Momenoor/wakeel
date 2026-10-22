<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which unfiled emails of the shared mailboxes a user sees (Users → Edit → Work account): all of them, or
 * only those that may be about their own matters. Unset: as their access says (all for whoever sees every
 * matter).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('unfiled_all')->nullable()->after('shared_senders')->comment('Unfiled emails of the shared mailboxes: all (1), those about their matters (0), as their access says (null)');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('unfiled_all');
        });
    }
};
