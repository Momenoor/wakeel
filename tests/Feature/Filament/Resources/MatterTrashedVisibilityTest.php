<?php

namespace Tests\Feature\Filament\Resources;

use App\Filament\Mms\Resources\Matters\MatterResource;
use App\Filament\Mms\Resources\Matters\Pages\ListMatters;
use App\Models\Matter;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A deleted matter is seen only on the Deleted tab, by whoever has it —
 * never on All or another tab, never in a search; without the Deleted
 * tab, nowhere at all.
 */
class MatterTrashedVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private bool $mayViewTrashed = true;

    private Matter $kept;

    private Matter $deleted;

    protected function setUp(): void
    {
        parent::setUp();

        Gate::before(fn ($user, string $ability) => $ability === 'ViewTrashed:Matter' ? $this->mayViewTrashed : true);
        Filament::setCurrentPanel('mms');
        $this->actingAs(User::factory()->create());

        $this->kept = Matter::factory()->create(['number' => '501', 'year' => '2026', 'initial_report_at' => null]);
        $this->deleted = Matter::factory()->create(['number' => '502', 'year' => '2026', 'initial_report_at' => null]);
        $this->deleted->delete();
    }

    public function test_only_the_deleted_tab_shows_a_deleted_matter_searched_or_not(): void
    {
        foreach (['all', 'in_progress'] as $tab) {
            Livewire::test(ListMatters::class)
                ->set('activeTab', $tab)
                ->assertCanSeeTableRecords([$this->kept])
                ->assertCanNotSeeTableRecords([$this->deleted])
                ->searchTable('502')
                ->assertCanNotSeeTableRecords([$this->deleted]);
        }

        Livewire::test(ListMatters::class)
            ->set('activeTab', 'deleted')
            ->searchTable('502')
            ->assertCanSeeTableRecords([$this->deleted])
            ->assertCanNotSeeTableRecords([$this->kept]);

        // Nor in the search at the top of every page.
        $this->assertNull(MatterResource::getGlobalSearchResults('502')->first());
    }

    public function test_without_the_deleted_tab_a_deleted_matter_is_nowhere(): void
    {
        $this->mayViewTrashed = false;

        $this->assertFalse(MatterResource::getEloquentQuery()->whereKey($this->deleted->id)->exists());
        $this->assertTrue(MatterResource::getEloquentQuery()->whereKey($this->kept->id)->exists());

        Livewire::test(ListMatters::class)
            ->searchTable('502')
            ->assertCanNotSeeTableRecords([$this->deleted]);

        // Not even by its address.
        $this->get(MatterResource::getUrl('view', ['record' => $this->deleted]))->assertNotFound();
    }
}
