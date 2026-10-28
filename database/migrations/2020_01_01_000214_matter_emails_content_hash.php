<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A received email's content — its words and the files it came with — as a fingerprint: two copies of one email
 * that reached two of our mailboxes are told the same by it (MatterEmail::copiesOf()), so filing one sets the
 * other aside. The body itself is not kept, only in its PDF.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('matter_emails', function (Blueprint $table) {
            $table->string('content_hash', 64)->nullable()->after('cc')->comment('sha256 of the words and files, to tell copies of one email');
        });
    }

    public function down(): void
    {
        Schema::table('matter_emails', function (Blueprint $table) {
            $table->dropColumn('content_hash');
        });
    }
};
