<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A request made by the system for someone with no user — the received date of a new matter, put to an
 * assistant who is only a party with an email — has nobody as its requester. Without this the insert was
 * refused ("Column 'request_by' cannot be null"), so that assistant got neither the request nor the email.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('matter_requests', function (Blueprint $table) {
            $table->unsignedBigInteger('request_by')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Required again only when every request has its requester.
        if (DB::table('matter_requests')->whereNull('request_by')->doesntExist()) {
            Schema::table('matter_requests', function (Blueprint $table) {
                $table->unsignedBigInteger('request_by')->nullable(false)->change();
            });
        }
    }
};
