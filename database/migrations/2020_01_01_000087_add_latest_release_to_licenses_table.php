<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('licenses', function (Blueprint $table) {
            $table->string('latest_version')->nullable()->comment('Newest published release, as last reported by the license server.');
            $table->text('latest_release_notes')->nullable();
            $table->timestamp('latest_released_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('licenses', function (Blueprint $table) {
            $table->dropColumn(['latest_version', 'latest_release_notes', 'latest_released_at']);
        });
    }
};
