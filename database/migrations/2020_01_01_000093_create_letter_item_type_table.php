<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Library items for several matter types instead of one: the single
 * type_id moves into a link table (existing links kept), then goes.
 * An item with no type is for every type, as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('letter_item_type', function (Blueprint $table) {
            $table->id();
            $table->foreignId('letter_item_id')->constrained('letter_items')->cascadeOnDelete();
            $table->foreignId('type_id')->constrained('types')->cascadeOnDelete();

            $table->unique(['letter_item_id', 'type_id']);
        });

        // Read first, write after the column is gone: dropping a column
        // rebuilds the table on SQLite, which would cascade-delete links
        // already written.
        $links = DB::table('letter_items')->whereNotNull('type_id')->orderBy('id')
            ->get(['id', 'type_id'])
            ->map(fn ($item) => ['letter_item_id' => $item->id, 'type_id' => $item->type_id])
            ->all();

        Schema::table('letter_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('type_id');
        });

        foreach (array_chunk($links, 500) as $chunk) {
            DB::table('letter_item_type')->insert($chunk);
        }
    }

    public function down(): void
    {
        Schema::table('letter_items', function (Blueprint $table) {
            $table->foreignId('type_id')->nullable()->after('text')->constrained('types')->nullOnDelete();
        });

        // Back to one type each: the first linked.
        DB::table('letter_item_type')->orderBy('id')->get()->groupBy('letter_item_id')->each(
            fn ($links, $itemId) => DB::table('letter_items')->where('id', $itemId)->update(['type_id' => $links->first()->type_id])
        );

        Schema::dropIfExists('letter_item_type');
    }
};
