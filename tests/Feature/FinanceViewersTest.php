<?php

namespace Tests\Feature;

use App\Filament\Mms\Pages\FinancialConfiguration;
use App\Filament\Mms\Pages\Payroll\EndOfServiceGratuityClosingVoucher;
use App\Filament\Mms\Pages\Reports\MyIncentiveReport;
use App\Filament\Mms\Resources\EmployeeProfiles\Pages\EditEmployeeProfile;
use App\Filament\Mms\Resources\EmployeeProfiles\RelationManagers\SalaryComponentsRelationManager;
use App\Filament\Mms\Resources\Incentive\IncentiveCalculations\IncentiveCalculationResource;
use App\Filament\Mms\Resources\PayrollRuns\PayrollRunResource;
use App\Models\EmployeeProfile;
use App\Models\Setting;
use App\Models\User;
use App\Support\FinanceViewers;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Salaries and incentives kept, for a while, to the users chosen under Settings → General.
 */
class FinanceViewersTest extends TestCase
{
    use RefreshDatabase;

    private User $chosen;

    private User $other;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('mms'));
        $role = Role::firstOrCreate(['name' => config('filament-shield.super_admin.name', 'super_admin'), 'guard_name' => 'web']);
        $this->chosen = User::factory()->create(['name' => 'Chosen'])->assignRole($role);
        $this->other = User::factory()->create(['name' => 'Other'])->assignRole($role);
    }

    /** @return array<string, bool> */
    private function seen(User $user): array
    {
        $this->actingAs($user);

        return [
            'incentives' => IncentiveCalculationResource::canAccess(),
            'incentives_nav' => IncentiveCalculationResource::shouldRegisterNavigation(),
            'payroll' => PayrollRunResource::canAccess(),
            'configuration' => FinancialConfiguration::canAccess(),
            'voucher' => EndOfServiceGratuityClosingVoucher::canAccess(),
            'salary' => SalaryComponentsRelationManager::canViewForRecord(new EmployeeProfile, EditEmployeeProfile::class),
        ];
    }

    public function test_with_no_one_chosen_the_permissions_decide_and_with_users_chosen_they_alone_see(): void
    {
        $all = ['incentives' => true, 'incentives_nav' => true, 'payroll' => true, 'configuration' => true, 'voucher' => true, 'salary' => true];
        $none = array_map(fn (): bool => false, $all);

        $this->assertSame([], FinanceViewers::ids());
        $this->assertSame($all, $this->seen($this->chosen));
        $this->assertSame($all, $this->seen($this->other));

        // One chosen: the other sees none of it, at its address either.
        Setting::set(FinanceViewers::SETTING, [(string) $this->chosen->id], 'general');
        Setting::clearCache();
        $this->assertSame([$this->chosen->id], FinanceViewers::ids());
        $this->assertSame($all, $this->seen($this->chosen));
        $this->assertSame($none, $this->seen($this->other));
        $this->actingAs($this->other)->get(IncentiveCalculationResource::getUrl())->assertForbidden();
        $this->actingAs($this->other)->get(PayrollRunResource::getUrl())->assertForbidden();
        $this->assertFalse(MyIncentiveReport::canAccess());

        // Emptied: the permissions again.
        Setting::set(FinanceViewers::SETTING, [], 'general');
        Setting::clearCache();
        $this->assertSame($all, $this->seen($this->other));
    }
}
