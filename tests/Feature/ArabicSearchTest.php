<?php

namespace Tests\Feature;

use App\Filament\Mms\Resources\Parties\Pages\ListParties;
use App\Models\Party;
use App\Models\User;
use App\Support\ArabicSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Arabic searched as it is meant, not as it is spelt: أحمد and احمد, علي and على, مهرة and مهره found alike — in
 * the app's own searches and in Filament's.
 */
class ArabicSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_spelling_finds_the_others(): void
    {
        foreach (['أحمد', 'إبراهيم', 'آمنة', 'علي', 'مهرة', 'مؤمن', 'هيئة'] as $name) {
            Party::factory()->create(['name' => $name]);
        }
        Party::factory()->create(['name' => 'محمود']);

        $found = fn (string $search): array => Party::query()->where('name', 'like', "%{$search}%")->orderBy('id')->pluck('name')->all();

        $this->assertSame(['أحمد'], $found('احمد'));
        $this->assertSame(['إبراهيم'], $found('ابراهيم'));
        $this->assertSame(['آمنة'], $found('امنه'));
        $this->assertSame(['علي'], $found('على'));
        $this->assertSame(['مهرة'], $found('مهره'));
        $this->assertSame(['مؤمن'], $found('مومن'));
        $this->assertSame(['هيئة'], $found('هيىه'));
        // And the other way round: as written, whatever it was saved as.
        $this->assertSame(['أحمد'], $found('إحمد'));
        $this->assertSame(['أحمد'], Party::query()->whereLike('name', '%احمد%')->pluck('name')->all());
    }

    public function test_a_search_without_arabic_is_left_as_it_was(): void
    {
        $this->assertSame('select * from "parties" where "name" like ?', Party::query()->where('name', 'like', '%12%')->toSql());
    }

    public function test_filaments_own_search_too(): void
    {
        Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        $this->actingAs(User::factory()->create()->assignRole('super-admin'));
        $ahmed = Party::factory()->create(['name' => 'أحمد علي']);
        $other = Party::factory()->create(['name' => 'محمود']);

        Livewire::test(ListParties::class)
            ->searchTable('احمد على')
            ->assertCanSeeTableRecords([$ahmed])
            ->assertCanNotSeeTableRecords([$other]);
    }

    public function test_text_matched_in_php_is_searched_alike(): void
    {
        $this->assertSame(ArabicSearch::normalize('أحمد علي مهرة'), ArabicSearch::normalize('احمد على مهره'));
    }
}
