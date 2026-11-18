<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Each file of a campaign under the name it was uploaded with ({path: name}): kept under a name of the system's
 * own, it went out, was listed and opened under that name instead (BulkMailCampaign::attachmentName()).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bulk_mail_campaigns', function (Blueprint $table) {
            $table->json('attachment_names')->nullable()->after('attachment_path');
        });
    }

    public function down(): void
    {
        Schema::table('bulk_mail_campaigns', function (Blueprint $table) {
            $table->dropColumn('attachment_names');
        });
    }
};
