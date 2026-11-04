<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An email that came in keeps its text once a reply to it is written (quoted in the reply, read from the mailbox
 * the first time), and the files added to a reply shared by link before it is sent are remembered on it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('matter_emails', function (Blueprint $table): void {
            $table->longText('body')->nullable()->after('cc');
            $table->json('shared_files')->nullable()->after('onedrive');
        });
    }

    public function down(): void
    {
        Schema::table('matter_emails', function (Blueprint $table): void {
            $table->dropColumn(['body', 'shared_files']);
        });
    }
};
