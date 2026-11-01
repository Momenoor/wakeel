<?php

namespace Tests\Feature;

use App\Filament\Mms\Resources\Matters\MatterResource;
use App\Filament\Mms\Resources\Matters\Pages\ViewMatter;
use App\Models\Matter;
use App\Models\MatterParty;
use App\Models\Party;
use App\Models\User;
use App\Support\MatterSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class MatterViewPolicyTest extends TestCase
{
    use RefreshDatabase;

    private function userWith(string ...$abilities): User
    {
        $user = User::factory()->create();
        foreach ($abilities as $ability) {
            $user->givePermissionTo(Permission::firstOrCreate(['name' => $ability, 'guard_name' => 'web']));
        }

        return $user;
    }

    public function test_view_permission_opens_any_matter(): void
    {
        $user = $this->userWith('View:Matter');

        $this->assertTrue($user->can('view', Matter::factory()->create()));
    }

    public function test_view_own_opens_only_matters_the_user_is_a_party_of(): void
    {
        $user = $this->userWith('ViewOwn:Matter');
        $party = Party::factory()->create(['user_id' => $user->id]);
        $mine = Matter::factory()->create();
        MatterParty::create(['matter_id' => $mine->id, 'role' => 'expert', 'type' => 'expert', 'party_id' => $party->id]);

        $this->assertTrue($user->fresh()->can('view', $mine));
        $this->assertFalse($user->fresh()->can('view', Matter::factory()->create()));
    }

    public function test_a_matter_picker_offers_only_the_matters_the_user_may_see(): void
    {
        $user = $this->userWith('ViewOwn:Matter');
        $party = Party::factory()->create(['user_id' => $user->id]);
        $mine = Matter::factory()->create(['number' => '771', 'year' => 2026]);
        $theirs = Matter::factory()->create(['number' => '772', 'year' => 2026]);
        MatterParty::create(['matter_id' => $mine->id, 'role' => 'expert', 'type' => 'expert', 'party_id' => $party->id]);

        $this->actingAs($user->fresh());
        $this->assertSame([$mine->id], array_keys(MatterSearch::options('77')));

        // Whoever sees every matter picks among all of them.
        $this->actingAs($this->userWith('ViewAny:Matter'));
        $this->assertEqualsCanonicalizing([$mine->id, $theirs->id], array_keys(MatterSearch::options('77')));
    }

    public function test_update_alone_does_not_open_a_matter(): void
    {
        $this->assertFalse($this->userWith('Update:Matter')->can('view', Matter::factory()->create()));
    }

    public function test_a_tab_permission_without_its_records_permission_does_not_block_the_page(): void
    {
        // Holds the Bulk mail tab's permission but cannot view campaigns: the
        // tab used to render and its relation manager aborted with a 403,
        // taking the whole matter page down with it.
        $user = $this->userWith('View:Matter', 'ViewOwn:Matter', 'View:MatterBulkMailTab');
        $matter = Matter::factory()->create();
        $party = Party::factory()->create(['user_id' => $user->id]);
        MatterParty::create(['matter_id' => $matter->id, 'role' => 'assistant', 'type' => 'assistant', 'party_id' => $party->id]);

        $this->actingAs($user->fresh());

        Livewire::test(ViewMatter::class, ['record' => $matter->getKey()])->assertSuccessful();
    }

    public function test_view_administered_opens_only_the_administered_assistants_matters(): void
    {
        $user = $this->userWith('ViewAdministered:Matter');
        $own = Party::factory()->create(['user_id' => $user->id]);
        $assistant = Party::factory()->assistant()->create();
        $assistant->administrators()->attach($user);

        $mine = Matter::factory()->create(['number' => '781', 'year' => 2026]);
        $theirs = Matter::factory()->create(['number' => '782', 'year' => 2026]);
        $nobodys = Matter::factory()->create(['number' => '783', 'year' => 2026]);
        MatterParty::create(['matter_id' => $mine->id, 'role' => 'expert', 'type' => 'expert', 'party_id' => $own->id]);
        MatterParty::create(['matter_id' => $theirs->id, 'role' => 'expert', 'type' => 'assistant', 'party_id' => $assistant->id]);

        // The assistant's matter, and not the ones the user is on themselves.
        $user = $user->fresh();
        $this->assertTrue($user->can('view', $theirs));
        $this->assertFalse($user->can('view', $mine));
        $this->assertFalse($user->can('view', $nobodys));

        $this->actingAs($user);
        $this->assertTrue(MatterResource::canViewAny());
        $this->assertSame([$theirs->id], Matter::query()->visibleTo($user)->pluck('id')->all());
        $this->assertSame([$theirs->id], array_keys(MatterSearch::options('78')));

        // With "View own matters" as well: their own too.
        $user->givePermissionTo(Permission::firstOrCreate(['name' => 'ViewOwn:Matter', 'guard_name' => 'web']));
        $user = $user->fresh();
        $this->assertTrue($user->can('view', $mine));
        $this->assertEqualsCanonicalizing([$mine->id, $theirs->id], Matter::query()->visibleTo($user)->pluck('id')->all());
    }
}
