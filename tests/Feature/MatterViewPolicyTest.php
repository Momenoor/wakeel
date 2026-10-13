<?php

namespace Tests\Feature;

use App\Models\Matter;
use App\Models\MatterParty;
use App\Models\Party;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_update_alone_does_not_open_a_matter(): void
    {
        $this->assertFalse($this->userWith('Update:Matter')->can('view', Matter::factory()->create()));
    }
}
