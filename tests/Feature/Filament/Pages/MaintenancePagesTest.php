<?php

namespace Tests\Feature\Filament\Pages;

use App\Filament\Mms\Pages\AccessControlMaintenance;
use App\Filament\Mms\Pages\FeeDataMaintenance;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The maintenance pages open with what their buttons change, read before
 * pressing one, above the live figures.
 */
class MaintenancePagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Gate::before(fn () => true);
        $this->actingAs(User::factory()->create());
        Filament::setCurrentPanel('mms');
    }

    public function test_fee_data_maintenance_warns_before_its_figures(): void
    {
        Livewire::test(FeeDataMaintenance::class)
            ->assertOk()
            ->assertSeeInOrder([
                __('Before you run anything'),
                __('These actions write to live financial records. Each one shows exactly what it will change before you confirm it, runs inside a transaction, and is safe to run twice — the second run finds nothing left to do.'),
                __('Office Owner'),
                __('Pending Repairs'),
            ]);
    }

    public function test_access_control_maintenance_warns_before_its_figures(): void
    {
        Livewire::test(AccessControlMaintenance::class)
            ->assertOk()
            ->assertSeeInOrder([
                __('Before you run anything'),
                __('Both only ever grant. No permission is revoked and no role is deleted, so a mistaken run cannot lock anybody out.'),
                __('Super Administrator Roles'),
            ]);
    }
}
