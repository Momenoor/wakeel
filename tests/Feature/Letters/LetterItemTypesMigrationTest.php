<?php

namespace Tests\Feature\Letters;

use App\Models\LetterItem;
use App\Models\Type;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Moving library items from one matter type to several keeps each item's
 * existing type.
 */
class LetterItemTypesMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2020_01_01_000093_create_letter_item_type_table.php';

    public function test_existing_types_are_kept(): void
    {
        $type = Type::factory()->create();

        // Back to the single type_id column, as a live server has it.
        Artisan::call('migrate:rollback', ['--path' => self::MIGRATION]);
        $this->assertTrue(Schema::hasColumn('letter_items', 'type_id'));

        $typed = DB::table('letter_items')->insertGetId(['group' => 'g', 'text' => 'typed', 'type_id' => $type->id, 'sort' => 0, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $untyped = DB::table('letter_items')->insertGetId(['group' => 'g', 'text' => 'untyped', 'type_id' => null, 'sort' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);

        Artisan::call('migrate', ['--path' => self::MIGRATION]);

        $this->assertFalse(Schema::hasColumn('letter_items', 'type_id'));
        $this->assertSame([$type->id], LetterItem::find($typed)->types->pluck('id')->all());
        $this->assertCount(0, LetterItem::find($untyped)->types);
    }
}
