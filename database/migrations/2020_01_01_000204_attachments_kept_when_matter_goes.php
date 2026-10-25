<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A matter deleted for good no longer fails on its files: financial evidence is never deleted, only hidden — its
 * row stays, so the matter it belonged to is emptied (MatterObserver deletes every other file with the matter).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attachments', function (Blueprint $table) {
            $table->dropForeign(['matter_id']);
            $table->foreign('matter_id')->references('id')->on('matters')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('attachments', function (Blueprint $table) {
            $table->dropForeign(['matter_id']);
            $table->foreign('matter_id')->references('id')->on('matters');
        });
    }
};
