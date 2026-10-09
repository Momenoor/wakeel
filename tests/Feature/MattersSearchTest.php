<?php

namespace Tests\Feature;

use App\Enums\FeeType;
use App\Filament\Mms\Resources\Matters\Pages\ListMatters;
use App\Filament\Mms\Resources\Matters\Tables\MattersTable;
use App\Models\Court;
use App\Models\Matter;
use App\Models\MatterParty;
use App\Models\Party;
use App\Models\Type;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The matters list's search: the text split into words on spaces and
 * / - : _ \ | +; numbers as the reference (number AND year), words OR'd
 * within their field and AND'd across fields — "contains" throughout.
 */
class MattersSearchTest extends TestCase
{
    use RefreshDatabase;

    private Matter $target;

    private Matter $other;

    private Matter $lookalike;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        $admin = User::factory()->create();
        $admin->assignRole('super-admin');
        $this->actingAs($admin);

        $this->target = Matter::factory()->create([
            'number' => '571', 'year' => '2009',
            'court_id' => Court::factory()->create(['name' => 'محاكم دبي'])->id,
            'type_id' => Type::factory()->create(['name' => 'عمالي'])->id,
        ]);
        MatterParty::create(['matter_id' => $this->target->id, 'role' => 'party', 'type' => 'plaintiff',
            'party_id' => Party::factory()->create(['name' => 'شركة المهاد للتجارة'])->id]);
        $this->target->fees()->create(['type' => FeeType::EXPERT_FEE, 'amount' => 10500, 'date' => now()]);

        $this->other = Matter::factory()->create([
            'number' => '2009', 'year' => '2021',
            'court_id' => Court::factory()->create(['name' => 'محاكم الشارقة'])->id,
            'type_id' => Type::factory()->create(['name' => 'تجاري'])->id,
        ]);

        // Number 571 too, but of 2015 — with a fee of 2009.
        $this->lookalike = Matter::factory()->create(['number' => '571', 'year' => '2015', 'court_id' => $this->other->court_id, 'type_id' => $this->other->type_id]);
        $this->lookalike->fees()->create(['type' => FeeType::EXPERT_FEE, 'amount' => 2009, 'date' => now()]);
    }

    /**
     * @return list<int>
     */
    private function found(string $search): array
    {
        return Livewire::test(ListMatters::class)
            ->searchTable($search)
            ->instance()
            ->getTableRecords()
            ->pluck('id')
            ->sort()
            ->values()
            ->all();
    }

    public function test_the_search_is_split_on_spaces_and_every_separator(): void
    {
        $this->assertSame(
            ['571', '2009', 'a', 'b', 'c', 'd', 'e', 'f', 'g'],
            MattersTable::searchWords('571/2009 a-b:c_d'.chr(92).'e|f+g'),
        );
        // Arabic digits as digits; joining words skipped.
        $this->assertSame(['571', '2009'], MattersTable::searchWords('٥٧١ لسنة ٢٠٠٩'));
    }

    public function test_the_number_and_year_are_found_written_any_way(): void
    {
        foreach (['571/2009', '2009/571', '571-2009', '571:2009', '571_2009', '571|2009', '571+2009', '571 2009', '571 لسنة 2009', '٥٧١/٢٠٠٩'] as $search) {
            $this->assertSame([$this->target->id], $this->found($search), $search);
        }
    }

    public function test_one_number_is_found_in_the_number_year_or_a_fee(): void
    {
        $this->assertSame([$this->target->id, $this->lookalike->id], $this->found('571'));
        $this->assertSame([$this->target->id], $this->found('10,500'));
        // One's year, another's number, a third's fee.
        $this->assertSame([$this->target->id, $this->other->id, $this->lookalike->id], $this->found('2009'));
    }

    public function test_words_are_or_within_their_field_and_and_across_fields(): void
    {
        $this->assertSame([$this->target->id], $this->found('المهاد'));
        $this->assertSame([$this->target->id], $this->found('عمالي'));
        // Same field (court): either.
        $this->assertSame([$this->target->id, $this->other->id, $this->lookalike->id], $this->found('دبي الشارقة'));
        // Different fields (court and party): both.
        $this->assertSame([$this->target->id], $this->found('دبي/المهاد'));
        $this->assertSame([], $this->found('الشارقة المهاد'));
        // A number with a word: both.
        $this->assertSame([$this->target->id], $this->found('571 عمالي'));
        // A word found nowhere: nothing.
        $this->assertSame([], $this->found('غيرموجود'));
    }
}
