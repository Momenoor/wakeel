<?php

namespace Tests\Feature;

use App\Filament\Mms\Resources\Parties\Pages\ListParties;
use App\Models\Party;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The parties list filtered by role and by an expert's sub role.
 */
class PartyRoleFilterTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $denied = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Everything allowed — but what a test denies.
        Gate::before(fn ($user, string $ability) => in_array($ability, $this->denied, true) ? false : true);
        Filament::setCurrentPanel('mms');
        $this->actingAs(User::factory()->create());
    }

    public function test_parties_are_filtered_by_role_and_sub_role(): void
    {
        $assistant = Party::factory()->assistant()->create();
        $certified = Party::factory()->certifiedExpert()->create();
        $employee = Party::factory()->employee()->create();
        $tenant = Party::factory()->tenant()->create();

        Livewire::test(ListParties::class)
            ->filterTable('role', ['employee', 'tenant'])
            ->assertCanSeeTableRecords([$employee, $tenant])
            ->assertCanNotSeeTableRecords([$assistant, $certified]);

        Livewire::test(ListParties::class)
            ->filterTable('role', ['expert'])
            ->assertCanSeeTableRecords([$assistant, $certified])
            ->assertCanNotSeeTableRecords([$employee, $tenant]);

        Livewire::test(ListParties::class)
            ->filterTable('sub_role', ['assistant'])
            ->assertCanSeeTableRecords([$assistant])
            ->assertCanNotSeeTableRecords([$certified, $employee, $tenant]);
    }

    public function test_external_entities_have_a_tab_of_their_own(): void
    {
        $police = Party::factory()->create(['name' => 'شرطة دبي', 'role' => ['role' => ['external']]]);
        $plaintiff = Party::factory()->create(['role' => ['role' => ['party']]]);

        Livewire::test(ListParties::class)
            ->set('activeTab', 'external')
            ->assertCanSeeTableRecords([$police])
            ->assertCanNotSeeTableRecords([$plaintiff]);
    }

    public function test_the_tab_shows_only_with_its_permission(): void
    {
        $this->denied = ['View:PartiesExternalTab'];

        $this->assertArrayNotHasKey('external', Livewire::test(ListParties::class)->instance()->getTabs());
    }
}
