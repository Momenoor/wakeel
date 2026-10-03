<?php

namespace Tests\Feature;

use App\Filament\Mms\Pages\AccessControlMaintenance;
use App\Filament\Mms\Pages\Chat;
use App\Filament\Mms\Pages\FeeDataMaintenance;
use App\Filament\Mms\Pages\FinancialConfiguration;
use App\Filament\Mms\Pages\OneDriveSettings;
use App\Filament\Mms\Resources\Courts\CourtResource;
use App\Filament\Mms\Resources\ExpertiseAreas\ExpertiseAreaResource;
use App\Filament\Mms\Resources\MailSenders\MailSenderResource;
use App\Filament\Mms\Resources\Types\TypeResource;
use App\Filament\Pms\Pages\PMSSettings;
use App\Filament\Shared\Clusters\Settings;
use App\Filament\Shared\Pages\SystemSettings;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Every settings, lookup and maintenance screen sits in the one Settings
 * cluster: a single sidebar entry, with the screens in its own menu.
 */
class SettingsNavigationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create()->assignRole(
            Role::firstOrCreate(['name' => config('filament-shield.super_admin.name', 'super_admin'), 'guard_name' => 'web'])
        ));
    }

    public function test_the_settings_screens_are_in_the_settings_cluster(): void
    {
        foreach ([
            SystemSettings::class,
            PMSSettings::class,
            FinancialConfiguration::class,
            OneDriveSettings::class,
            MailSenderResource::class,
            TypeResource::class,
            ExpertiseAreaResource::class,
            CourtResource::class,
            FeeDataMaintenance::class,
            AccessControlMaintenance::class,
        ] as $screen) {
            $this->assertSame(Settings::class, $screen::getCluster(), $screen);
        }
    }

    public function test_the_sidebar_has_one_settings_entry_instead_of_each_screen(): void
    {
        Filament::setCurrentPanel('mms');

        $html = $this->get(Chat::getUrl())->assertSuccessful()->getContent();

        $this->assertStringContainsString('href="'.Settings::getUrl().'"', $html);
        $this->assertStringNotContainsString('href="'.CourtResource::getUrl().'"', $html);
        $this->assertStringNotContainsString('href="'.SystemSettings::getUrl().'"', $html);
    }

    public function test_the_settings_menu_is_in_order(): void
    {
        Filament::setCurrentPanel('mms');

        $html = $this->get(SystemSettings::getUrl())->assertSuccessful()->getContent();

        $urls = [
            // General
            SystemSettings::getUrl(),
            FinancialConfiguration::getUrl(),
            OneDriveSettings::getUrl(),
            MailSenderResource::getUrl(),
            // Master Data
            TypeResource::getUrl(),
            ExpertiseAreaResource::getUrl(),
            CourtResource::getUrl(),
            // Maintenance
            FeeDataMaintenance::getUrl(),
            AccessControlMaintenance::getUrl(),
        ];

        $positions = array_map(fn (string $url) => strpos($html, 'href="'.$url.'"'), $urls);

        $this->assertNotContains(false, $positions);
        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions);
    }

    public function test_both_panels_fill_the_same_settings_cluster(): void
    {
        $mms = Filament::getPanel('mms')->getClusteredComponents(Settings::class);
        $pms = Filament::getPanel('pms')->getClusteredComponents(Settings::class);

        $this->assertContains(SystemSettings::class, $mms);
        $this->assertContains(CourtResource::class, $mms);
        $this->assertNotContains(PMSSettings::class, $mms);

        $this->assertContains(SystemSettings::class, $pms);
        $this->assertContains(PMSSettings::class, $pms);
        $this->assertNotContains(CourtResource::class, $pms);
    }
}
