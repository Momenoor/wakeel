<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Emails of a bulk mail campaign with no matter are remembered too — for
 * their replies — and each reply keeps where its PDF and files were put.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('matter_emails', function (Blueprint $table) {
            $table->unsignedBigInteger('matter_id')->nullable()->change();
            $table->json('files')->nullable()->after('to')->comment('received: [{name, path}] on the public disk — its PDF first');
        });
    }

    public function down(): void
    {
        Schema::table('matter_emails', function (Blueprint $table) {
            $table->dropColumn('files');
        });
    }
};
