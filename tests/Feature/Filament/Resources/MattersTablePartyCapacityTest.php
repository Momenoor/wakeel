<?php

namespace Tests\Feature\Filament\Resources;

use App\Filament\Mms\Resources\Matters\Pages\ListMatters;
use App\Models\Matter;
use App\Models\MatterParty;
use App\Models\Party;
use App\Models\Type;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The matters list names each party's side as the matter's type calls it — an appeal's appellant, a lease's
 * landlord — as the matter's own page does, and on every screen.
 */
class MattersTablePartyCapacityTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_party_shows_the_side_as_the_matters_type_calls_it(): void
    {
        Gate::before(fn () => true);
        Filament::setCurrentPanel('mms');
        $this->actingAs(User::factory()->create());

        $appeal = Type::factory()->create(['party_capacities' => ['plaintiff' => 'مستأنف', 'defendant' => 'مستأنف ضده']]);
        $plain = Type::factory()->create(['party_capacities' => null]);

        $matter = Matter::factory()->create(['type_id' => $appeal->id]);
        MatterParty::create(['matter_id' => $matter->id, 'role' => 'party', 'type' => 'plaintiff', 'party_id' => Party::factory()->create(['name' => 'Leva Engineering'])->id]);
        MatterParty::create(['matter_id' => $matter->id, 'role' => 'party', 'type' => 'defendant', 'party_id' => Party::factory()->create(['name' => 'Bin Hasa Contracting'])->id]);

        $other = Matter::factory()->create(['type_id' => $plain->id]);
        MatterParty::create(['matter_id' => $other->id, 'role' => 'party', 'type' => 'plaintiff', 'party_id' => Party::factory()->create(['name' => 'Palma Development'])->id]);

        Livewire::test(ListMatters::class)
            ->assertSee('مستأنف')
            ->assertSee('مستأنف ضده')
            ->assertSee('Leva Engineering')
            // A type without its own names: the usual one.
            ->assertSee(__('Plaintiff'))
            // Shown on every screen, not hidden on small or high-density ones.
            ->assertDontSee('<span class="type-label">مستأنف</span>', false);
    }
}
