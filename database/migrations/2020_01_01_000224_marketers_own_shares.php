<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Each marketer with a share of their own — a percentage of the fees' basic amount, or a fixed amount — instead of
 * parts of one share on the matter (MarketingCommissions). The marketers already there keep what they earn: each
 * one's part of the matter's share becomes their own percentage or amount.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('matter_party', function (Blueprint $table): void {
            $table->decimal('marketing_rate', 5, 2)->nullable()->after('marketing_part')->comment('a marketer\'s share: a percentage of the fees\' basic amount (a staff marketer: after the third parties\' commissions); none: the setting\'s');
            $table->decimal('marketing_fixed', 15, 2)->nullable()->after('marketing_rate')->comment('a marketer\'s share as a fixed amount, instead of a percentage');
        });

        $defaultRate = (float) (DB::table('settings')->where('key', 'marketing_share_pct')->value('value') ?? 25);
        $matters = DB::table('matters')->whereIn('id', DB::table('matter_party')->where('role', 'marketer')->select('matter_id'))->get(['id', 'marketing_rate', 'marketing_fixed']);

        foreach ($matters as $matter) {
            $marketers = DB::table('matter_party')->where('matter_id', $matter->id)->where('role', 'marketer')->get(['id', 'marketing_part']);
            $typed = (float) $marketers->sum(fn ($marketer): float => (float) ($marketer->marketing_part ?? 0));

            foreach ($marketers as $marketer) {
                $part = $typed > 0 ? (float) ($marketer->marketing_part ?? 0) / $typed : 1 / max(1, $marketers->count());

                DB::table('matter_party')->where('id', $marketer->id)->update($matter->marketing_fixed !== null
                    ? ['marketing_fixed' => round((float) $matter->marketing_fixed * $part, 2)]
                    : ['marketing_rate' => round((float) ($matter->marketing_rate ?? $defaultRate) * $part, 2)]);
            }
        }

        Schema::table('matter_party', function (Blueprint $table): void {
            $table->dropColumn('marketing_part');
        });

        Schema::table('matters', function (Blueprint $table): void {
            $table->dropColumn(['marketing_rate', 'marketing_fixed']);
        });
    }

    public function down(): void
    {
        Schema::table('matters', function (Blueprint $table): void {
            $table->decimal('marketing_rate', 5, 2)->nullable();
            $table->decimal('marketing_fixed', 15, 2)->nullable();
        });

        Schema::table('matter_party', function (Blueprint $table): void {
            $table->decimal('marketing_part', 5, 2)->nullable();
            $table->dropColumn(['marketing_rate', 'marketing_fixed']);
        });
    }
};
