<?php

namespace Tests\Feature;

use App\Filament\Mms\Pages\AdminDashboard;
use App\Filament\Mms\Resources\Matters\Pages\ViewMatter;
use App\Models\Matter;
use App\Models\MatterTypeIncentiveConfig;
use App\Models\Type;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * A matter's incentive calculation type on its overview before any calculation runs, and an hourly pop-up for
 * admins naming the matter types linked to none (their matters' fees the calculation skips).
 */
class MatterTypeIncentiveLinkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('en');
        Filament::setCurrentPanel('mms');
        Gate::before(fn () => true);
        Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    }

    private function user(?string $role = null, string $email = 'someone@firm.ae'): User
    {
        $user = User::factory()->create(['email' => $email]);

        if ($role) {
            $user->assignRole($role);
        }

        return $user;
    }

    public function test_the_overview_shows_how_its_incentive_is_worked_out(): void
    {
        $this->actingAs($this->user('super-admin'));
        $config = MatterTypeIncentiveConfig::create(['name' => 'Expert tiers', 'calculation_type' => 'fixed', 'fixed_percentage' => 8, 'assistant_rate' => 10]);
        $linked = Matter::factory()->create(['type_id' => Type::factory()->create(['incentive_config_id' => $config->id])->id]);
        $unlinked = Matter::factory()->create(['type_id' => Type::factory()->create(['incentive_config_id' => null])->id]);

        Livewire::test(ViewMatter::class, ['record' => $linked->getRouteKey()])
            ->assertSee(__('Incentive Calculation Type'))
            ->assertSee('Expert tiers')
            ->assertSee(__('Fixed — fixed % for all fees'));

        Livewire::test(ViewMatter::class, ['record' => $unlinked->getRouteKey()])
            ->assertSee(__('Not linked'))
            ->assertSee(__('The incentive calculation skips its fees until its type is linked to one.'));
    }

    public function test_admins_are_told_of_a_type_linked_to_no_incentive_calculation_type(): void
    {
        $config = MatterTypeIncentiveConfig::create(['name' => 'Expert tiers', 'calculation_type' => 'tiered', 'assistant_rate' => 10]);
        Type::factory()->create(['name' => 'Linked type', 'incentive_config_id' => $config->id]);
        Type::factory()->create(['name' => 'Inactive type', 'incentive_config_id' => null, 'active' => false]);
        $new = Type::factory()->create(['name' => 'Arbitration', 'incentive_config_id' => null]);

        $this->actingAs($this->user('admin'));
        $this->get(AdminDashboard::getUrl(panel: 'mms'))
            ->assertSuccessful()
            ->assertSee('wakeel-types-without-incentive', false)
            ->assertSee('1 matter type has no incentive calculation type')
            ->assertSee('Arbitration')
            ->assertDontSee('Inactive type')
            ->assertSee("close-modal', { id: 'wakeel-types-without-incentive' }", false);

        $this->actingAs($this->user(email: 'staff@firm.ae'));
        $this->get(AdminDashboard::getUrl(panel: 'mms'))->assertDontSee('wakeel-types-without-incentive', false);

        // Read once and kept for every page; linked: read again, and no more pop-up.
        $this->assertSame([$new->id => 'Arbitration'], Cache::get(Type::WITHOUT_INCENTIVE_CACHE));
        $new->update(['incentive_config_id' => $config->id]);
        $this->assertFalse(Cache::has(Type::WITHOUT_INCENTIVE_CACHE));
        $this->actingAs(User::query()->where('email', 'someone@firm.ae')->sole());
        $this->get(AdminDashboard::getUrl(panel: 'mms'))->assertDontSee('wakeel-types-without-incentive', false);
    }
}
