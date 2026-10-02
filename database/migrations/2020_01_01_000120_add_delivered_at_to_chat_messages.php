<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * When a chat message reached the other side (their Wakeel open): the
 * second tick. Read is the conversation's last_read_at, already kept.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->timestamp('delivered_at')->nullable()->after('body');
        });

        // Messages from before: taken as delivered when sent.
        DB::table('chat_messages')->update(['delivered_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropColumn('delivered_at');
        });
    }
};
