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
use App\Filament\Shared\Pages\AnnouncementSettings;
use App\Filament\Shared\Pages\GeneralSettings;
use App\Filament\Shared\Pages\IntegrationsSettings;
use App\Filament\Shared\Pages\LettersSettings;
use App\Filament\Shared\Pages\MailCollectingSettings;
use App\Filament\Shared\Pages\MailServerSettings;
use App\Filament\Shared\Pages\NotificationSettings;
use App\Filament\Shared\Pages\OfflineSettings;
use App\Filament\Shared\Pages\Performance;
use App\Filament\Shared\Pages\SystemSettings;
use App\Filament\Shared\Pages\SystemUpdates;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Every settings, lookup and maintenance screen sits in the one Settings
 * cluster: a single sidebar entry, with the screens in its own menu, grouped
 * General, Communication, Integrations, Master Data and Maintenance.
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
            GeneralSettings::class,
            MailServerSettings::class,
            LettersSettings::class,
            NotificationSettings::class,
            AnnouncementSettings::class,
            IntegrationsSettings::class,
            OfflineSettings::class,
            SystemUpdates::class,
            Performance::class,
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
        $this->assertStringNotContainsString('href="'.GeneralSettings::getUrl().'"', $html);
    }

    public function test_the_settings_menu_is_grouped_and_in_order(): void
    {
        Filament::setCurrentPanel('mms');

        $html = $this->get(GeneralSettings::getUrl())->assertSuccessful()->getContent();

        $urls = [
            // General
            GeneralSettings::getUrl(),
            // Communication
            MailSenderResource::getUrl(),
            MailServerSettings::getUrl(),
            LettersSettings::getUrl(),
            NotificationSettings::getUrl(),
            AnnouncementSettings::getUrl(),
            // Integrations
            IntegrationsSettings::getUrl(),
            OneDriveSettings::getUrl(),
            // Master Data
            TypeResource::getUrl(),
            ExpertiseAreaResource::getUrl(),
            CourtResource::getUrl(),
            FinancialConfiguration::getUrl(),
            // Maintenance
            OfflineSettings::getUrl(),
            FeeDataMaintenance::getUrl(),
            AccessControlMaintenance::getUrl(),
            SystemUpdates::getUrl(),
            Performance::getUrl(),
        ];

        $positions = array_map(fn (string $url) => strpos($html, 'href="'.$url.'"'), $urls);

        $this->assertNotContains(false, $positions);
        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions);

        // The old single page is no longer a menu entry — only a landing.
        $this->assertStringNotContainsString('href="'.SystemSettings::getUrl().'"', $html);
    }

    public function test_the_menu_groups_are_named_as_intended(): void
    {
        Filament::setCurrentPanel('mms');
        app()->setLocale('en');

        $groups = [
            'General' => [GeneralSettings::class],
            // The mailboxes — the list, the system's email, how they are read — with the letters.
            'Communication' => [MailSenderResource::class, MailServerSettings::class, MailCollectingSettings::class, LettersSettings::class, NotificationSettings::class, AnnouncementSettings::class],
            'Integrations' => [IntegrationsSettings::class, OneDriveSettings::class],
            'Master Data' => [TypeResource::class, ExpertiseAreaResource::class, CourtResource::class, FinancialConfiguration::class],
            'Maintenance' => [OfflineSettings::class, FeeDataMaintenance::class, AccessControlMaintenance::class, SystemUpdates::class, Performance::class],
        ];

        foreach ($groups as $group => $screens) {
            foreach ($screens as $screen) {
                $this->assertSame($group, $screen::getNavigationGroup(), $screen);
            }
        }

        $this->assertSame(array_keys($groups), array_values(array_unique(array_map(
            fn (string $screen): string => $screen::getNavigationGroup(),
            array_merge(...array_values($groups)),
        ))));

        // The sort numbers keep the groups in that order.
        $sorts = collect($groups)->map(fn (array $screens): array => array_map(fn (string $screen): int => $screen::getNavigationSort(), $screens));
        $this->assertSame($sorts->flatten()->sort()->values()->all(), $sorts->flatten()->values()->all());
    }

    public function test_pms_settings_is_in_master_data(): void
    {
        $this->assertSame(__('Master Data'), PMSSettings::getNavigationGroup());
    }

    public function test_both_panels_fill_the_same_settings_cluster(): void
    {
        $mms = Filament::getPanel('mms')->getClusteredComponents(Settings::class);
        $pms = Filament::getPanel('pms')->getClusteredComponents(Settings::class);

        foreach ([SystemSettings::class, GeneralSettings::class, MailServerSettings::class, LettersSettings::class, NotificationSettings::class, AnnouncementSettings::class, IntegrationsSettings::class, OfflineSettings::class] as $page) {
            $this->assertContains($page, $mms);
            $this->assertContains($page, $pms);
        }

        $this->assertContains(CourtResource::class, $mms);
        $this->assertNotContains(PMSSettings::class, $mms);

        $this->assertContains(PMSSettings::class, $pms);
        $this->assertNotContains(CourtResource::class, $pms);
    }
}
