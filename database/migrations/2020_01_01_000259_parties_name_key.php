<?php

use App\Support\PartyNames;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A party's name as compared (PartyNames::key()), to find a duplicate before it is made — and every name there
 * already tidied: the spaces and tabs about it and the runs of them in it (one party was "\tالدار العقارية" and
 * "الدار العقارية\t" at once).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parties', function (Blueprint $table) {
            $table->string('name_key')->nullable()->index()->after('name');
        });

        DB::table('parties')->select(['id', 'name'])->orderBy('id')->chunkById(500, function ($parties): void {
            foreach ($parties as $party) {
                if ($party->name === null) {
                    continue;
                }

                DB::table('parties')->where('id', $party->id)->update([
                    'name' => PartyNames::tidy($party->name),
                    'name_key' => PartyNames::key($party->name),
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('parties', function (Blueprint $table) {
            $table->dropIndex(['name_key']);
            $table->dropColumn('name_key');
        });
    }
};
