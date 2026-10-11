<?php

use App\Support\ScreenPermissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every email a matter's party sends to a mailbox Wakeel sends from — not
 * only replies — kept with the matter it is about. One whose matter is not
 * certain waits on the Unfiled emails page for a person to choose: its
 * matters to choose from, and whether it is waiting, filed or set aside.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('matter_emails', function (Blueprint $table) {
            $table->string('status', 10)->nullable()->after('direction')->comment('an incoming email: pending (a person chooses its matter) | filed | dismissed');
            $table->json('cc')->nullable()->after('to')->comment('received: who it was copied to');
            $table->json('candidates')->nullable()->after('files')->comment('pending: the matters it may be about');
            $table->string('matched_by', 30)->nullable()->after('candidates')->comment('an incoming email: how its matter was known');
            $table->index(['direction', 'status']);
        });

        ScreenPermissions::grant('View:UnfiledEmails', ['ViewAny:Matter', 'ViewOwn:Matter']);
    }

    public function down(): void
    {
        Schema::table('matter_emails', function (Blueprint $table) {
            $table->dropIndex(['direction', 'status']);
            $table->dropColumn(['status', 'cc', 'candidates', 'matched_by']);
        });

        DB::table('permissions')->where('name', 'View:UnfiledEmails')->delete();
    }
};
