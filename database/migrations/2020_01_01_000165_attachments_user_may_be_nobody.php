<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An attachment added by no one: an email's PDF kept by the automatic check
 * of the inboxes, with nobody signed in. Without this the PDF was refused
 * ("Column 'user_id' cannot be null") and the email kept without it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attachments', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Back to required only once no attachment is without a user.
        if (DB::table('attachments')->whereNull('user_id')->doesntExist()) {
            Schema::table('attachments', function (Blueprint $table) {
                $table->foreignId('user_id')->nullable(false)->change();
            });
        }
    }
};
