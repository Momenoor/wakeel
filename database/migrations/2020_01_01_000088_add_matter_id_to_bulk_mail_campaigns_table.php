<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The matter a campaign is about — its details become {{matter.*}}
 * placeholders. Null for a general mailing not tied to any matter.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bulk_mail_campaigns', function (Blueprint $table) {
            $table->foreignId('matter_id')->nullable()->after('from_sender_key')
                ->constrained('matters')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bulk_mail_campaigns', function (Blueprint $table) {
            $table->dropConstrainedForeignId('matter_id');
        });
    }
};
