<?php

use App\Models\Matter;
use App\Services\MMS\MatterHistory;
use App\Support\ScreenPermissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A matter's History tab: each activity knows the matter it is about (written as it is logged, App\Services\MMS\
 * MatterHistory) — found there after what it was about is deleted. Those logged before: their matter found from
 * what they are about, else from what they logged of it. The tab given to whoever sees matters.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('activity_log', 'matter_id')) {
            Schema::table('activity_log', function (Blueprint $table) {
                $table->unsignedBigInteger('matter_id')->nullable()->after('subject_id');
                $table->index(['matter_id', 'id']);
            });
        }

        DB::table('activity_log')->where('subject_type', (new Matter)->getMorphClass())->whereNull('matter_id')->update(['matter_id' => DB::raw('subject_id')]);

        foreach (array_keys(MatterHistory::KINDS) as $class) {
            $model = new $class;
            if ($class === Matter::class || ! Schema::hasColumn($model->getTable(), 'matter_id')) {
                continue;
            }

            // From what it is about, where it still is.
            if (DB::getDriverName() === 'mysql') {
                DB::statement('UPDATE activity_log a JOIN `'.$model->getTable().'` t ON t.id = a.subject_id SET a.matter_id = t.matter_id WHERE a.subject_type = ? AND a.matter_id IS NULL', [$model->getMorphClass()]);
            } else {
                DB::table($model->getTable())->whereNotNull('matter_id')->select(['id', 'matter_id'])->orderBy('id')->chunk(1000, function ($rows) use ($model) {
                    foreach ($rows->groupBy('matter_id') as $matterId => $group) {
                        DB::table('activity_log')->where('subject_type', $model->getMorphClass())->whereNull('matter_id')
                            ->whereIn('subject_id', $group->pluck('id'))->update(['matter_id' => $matterId]);
                    }
                });
            }

            // Deleted since: from what was logged of it.
            DB::table('activity_log')->where('subject_type', $model->getMorphClass())->whereNull('matter_id')
                ->select(['id', 'attribute_changes', 'properties'])->orderBy('id')
                ->chunkById(1000, function ($rows) {
                    foreach ($rows as $row) {
                        $changes = json_decode((string) ($row->attribute_changes ?: $row->properties), true) ?: [];
                        $matterId = $changes['attributes']['matter_id'] ?? $changes['old']['matter_id'] ?? null;
                        if (filled($matterId)) {
                            DB::table('activity_log')->where('id', $row->id)->update(['matter_id' => (int) $matterId]);
                        }
                    }
                });
        }

        ScreenPermissions::grant(ScreenPermissions::MATTER_HISTORY_TAB);
    }

    public function down(): void
    {
        if (Schema::hasColumn('activity_log', 'matter_id')) {
            Schema::table('activity_log', function (Blueprint $table) {
                $table->dropIndex(['matter_id', 'id']);
                $table->dropColumn('matter_id');
            });
        }
    }
};
