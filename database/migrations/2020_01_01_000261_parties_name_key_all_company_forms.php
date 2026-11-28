<?php

use App\Support\PartyNames;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Every party's name key again (PartyNames::key()): every company form left out of it however written — "ش ذ م م"
 * as "ش.ذ.م.م" and "ذ.م.م", "شركة ذات مسئولية محدودة" and its misspellings, "مساهمة عامة" or "خاصة" as "ش.م.ع" and
 * "ش.م.خ", "LLC" and "PJSC" — so a company entered with one and with another is found as the same.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('parties')->whereNotNull('name')->select(['id', 'name', 'name_key'])->orderBy('id')->chunkById(500, function ($parties): void {
            foreach ($parties as $party) {
                $key = PartyNames::key($party->name);

                if ($key !== $party->name_key) {
                    DB::table('parties')->where('id', $party->id)->update(['name_key' => $key]);
                }
            }
        });
    }

    public function down(): void
    {
        // The keys stay: the earlier ones were only less thorough.
    }
};
