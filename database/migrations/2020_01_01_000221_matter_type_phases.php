<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A matter type's phases (a bankruptcy's first, second, third), in order — and the phase each of its matters is in:
 * chosen when the matter is made (a supplementary one: the next), {{matter.phase}} in letters and folders. Until now a
 * custom field held it (App\Services\MMS\MatterPhases::fromField()).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('types', function (Blueprint $table) {
            $table->boolean('has_phases')->default(false)->after('has_creditors')->comment('Its matters are each in one of its phases');
            $table->json('phases')->nullable()->after('has_phases')->comment('Its phases\' names, in order');
        });

        Schema::table('matters', function (Blueprint $table) {
            $table->string('phase', 100)->nullable()->after('type_id')->index()->comment('One of its type\'s phases');
        });
    }

    public function down(): void
    {
        Schema::table('matters', function (Blueprint $table) {
            $table->dropIndex(['phase']);
            $table->dropColumn('phase');
        });
        Schema::table('types', function (Blueprint $table) {
            $table->dropColumn(['has_phases', 'phases']);
        });
    }
};
