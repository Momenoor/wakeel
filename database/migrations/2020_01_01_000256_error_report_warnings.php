<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Warnings on the Error tracking screen too (ErrorTracker::warning): a background request that failed in the
 * browser, emails in a matter's OneDrive that could not be read. The same warning again is not a new row: the
 * one still unresolved counts it (times) and says when it was last seen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('error_reports', function (Blueprint $table) {
            $table->string('level', 10)->default('error')->after('fingerprint')->index()
                ->comment('error (an exception) or warning (ErrorTracker::warning)');
            $table->unsignedInteger('times')->default(1)->after('level')
                ->comment('how many times this row stands for: a warning seen again is counted here');
            $table->timestamp('last_seen_at')->nullable()->after('created_at')
                ->comment('when a warning was last seen; null: only when it was created');
        });
    }

    public function down(): void
    {
        Schema::table('error_reports', function (Blueprint $table) {
            $table->dropIndex(['level']);
            $table->dropColumn(['level', 'times', 'last_seen_at']);
        });
    }
};
