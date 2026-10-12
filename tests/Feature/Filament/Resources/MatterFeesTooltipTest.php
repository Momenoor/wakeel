<?php

namespace Tests\Feature\Filament\Resources;

use App\Enums\FeeType;
use App\Filament\Mms\Resources\Matters\Pages\ListMatters;
use App\Filament\Mms\Resources\Matters\Tables\MattersTable;
use App\Models\Matter;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The matters list's fees column shows a matter's total; hovered, each
 * fee that makes it up — type, date, amount, collected — and the total.
 */
class MatterFeesTooltipTest extends TestCase
{
    use RefreshDatabase;

    public function test_hovering_the_fees_shows_each_fee_and_the_total(): void
    {
        Gate::before(fn () => true);
        Filament::setCurrentPanel('mms');
        $this->actingAs(User::factory()->create());

        $matter = Matter::factory()->create();
        [$first, $second] = array_slice(array_filter(FeeType::cases(), fn (FeeType $t) => ! $t->isNegative()), 0, 2);
        $matter->fees()->create(['type' => $first, 'amount' => 1000, 'date' => '2026-01-10', 'description' => 'Expert <b>fee</b>']);
        $fee = $matter->fees()->create(['type' => $second, 'amount' => 250, 'date' => '2026-02-01']);
        $fee->allocations()->create(['matter_id' => $matter->id, 'amount' => 100, 'date' => '2026-02-05']);

        $tooltip = (string) MattersTable::feesBreakdown($matter->load('fees.allocations'));

        $this->assertStringContainsString($first->getLabel(), $tooltip);
        $this->assertStringContainsString($second->getLabel(), $tooltip);
        $this->assertStringContainsString('1,000.00', $tooltip);
        $this->assertStringContainsString('10/01/2026', $tooltip);
        // The total and what was collected of it.
        $this->assertStringContainsString('1,250.00', $tooltip);
        $this->assertStringContainsString('100.00', $tooltip);
        // A description's own markup shown as text, not run.
        $this->assertStringContainsString('Expert &lt;b&gt;fee&lt;/b&gt;', $tooltip);

        // No fees: no tooltip.
        $this->assertNull(MattersTable::feesBreakdown(Matter::factory()->create()->load('fees.allocations')));

        // On the list itself.
        Livewire::test(ListMatters::class)->assertSee('x-tooltip', false)->assertSee('1,250.00');
    }
}
