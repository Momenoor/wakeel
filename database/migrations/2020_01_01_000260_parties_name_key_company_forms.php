<?php

use App\Support\PartyNames;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Every party's name key again (PartyNames::key()): the company's form written out ("شركة مساهمة عامة", "شركة ذات
 * مسؤولية محدودة" / "مسئولية") now left out of it as "ش.م.ع" and "ذ.م.م" are, so those names find their duplicates.
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
