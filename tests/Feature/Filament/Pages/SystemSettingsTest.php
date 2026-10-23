<?php

namespace Tests\Feature\Filament\Pages;

use App\Filament\Mms\Resources\CalendarEvents\Schemas\CalendarEventForm;
use App\Filament\Shared\Pages\AnnouncementSettings;
use App\Filament\Shared\Pages\GeneralSettings;
use App\Filament\Shared\Pages\IntegrationsSettings;
use App\Filament\Shared\Pages\LettersSettings;
use App\Filament\Shared\Pages\MailCollectingSettings;
use App\Filament\Shared\Pages\MailServerSettings;
use App\Filament\Shared\Pages\OfflineSettings;
use App\Filament\Shared\Pages\SettingsFormPage;
use App\Filament\Shared\Pages\SystemSettings;
use App\Filament\Support\EmailSendFields;
use App\Models\Setting;
use App\Models\User;
use App\Services\Installer\EnvironmentFileWriter;
use App\Services\MMS\MatterReplyCollector;
use App\Services\MMS\OutlookCalendarService;
use App\Support\Branding;
use App\Support\CompanyContact;
use App\Support\Integrations;
use App\Support\LiveRefresh;
use App\Support\ScreenPermissions;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * System Settings, once one page of tabs, is now a page for each kind of
 * setting: each opens on its old tab's permission, saves only its own keys,
 * and the old address still lands on one of them.
 */
class SystemSettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private string $envPath;

    protected function setUp(): void
    {
        parent::setUp();

        // 'super-admin' (hyphenated) is the role name Shield's config actually
        // points at — see config/filament-shield.php.
        Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('super-admin');
        $this->actingAs($this->admin);

        Filament::setCurrentPanel('mms');

        // Saving General writes APP_LOCALE to .env — never the real one.
        $this->envPath = sys_get_temp_dir().'/system-settings-test-'.uniqid().'.env';
        File::put($this->envPath, "APP_LOCALE=ar\n");
        $this->app->instance(EnvironmentFileWriter::class, new EnvironmentFileWriter($this->envPath));
    }

    protected function tearDown(): void
    {
        File::delete($this->envPath);

        // Setting's in-memory runtime cache is a static property that outlives
        // RefreshDatabase's per-test rollback. Saving app_offline as true and
        // reading it back re-warms that cache with the offline flag on — left
        // uncleared, every test running afterward in the same process finds
        // the app "offline" regardless of what the database actually holds.
        Setting::clearCache();

        parent::tearDown();
    }

    /**
     * @return array<class-string<SettingsFormPage>, string|null> the tab permission each page answers to
     */
    private function pages(): array
    {
        return [
            GeneralSettings::class => ScreenPermissions::SETTINGS_GENERAL_TAB,
            MailServerSettings::class => ScreenPermissions::SETTINGS_EMAIL_TAB,
            LettersSettings::class => ScreenPermissions::SETTINGS_LETTERS_TAB,
            AnnouncementSettings::class => ScreenPermissions::SETTINGS_NOTIFICATIONS_TAB,
            OfflineSettings::class => ScreenPermissions::SETTINGS_MAINTENANCE_TAB,
            IntegrationsSettings::class => null,
        ];
    }

    /**
     * @param  list<string>  $permissions
     */
    private function userWith(array $permissions): User
    {
        $user = User::factory()->create();

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $user->givePermissionTo($permissions);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }

    public function test_every_page_opens_for_the_super_admin(): void
    {
        foreach (array_keys($this->pages()) as $page) {
            $this->get($page::getUrl())->assertSuccessful();
        }
    }

    public function test_guest_cannot_access_the_pages(): void
    {
        auth()->logout();

        $this->get(SystemSettings::getUrl())->assertRedirect();
        $this->get(GeneralSettings::getUrl())->assertRedirect();
    }

    public function test_each_page_needs_the_permission_of_the_tab_it_was(): void
    {
        foreach ($this->pages() as $page => $tab) {
            if ($tab === null) {
                continue;
            }

            // The tab alone is not enough: the page-level permission opened System Settings.
            $this->actingAs($this->userWith([$tab]));
            $this->assertFalse($page::canAccess(), "{$page} opened without View:SystemSettings");

            // View:SystemSettings alone opens none of the tabs.
            $this->actingAs($this->userWith(['View:SystemSettings']));
            $this->assertFalse($page::canAccess(), "{$page} opened without its tab permission");

            // Both open that page — and only that page.
            $this->actingAs($this->userWith(['View:SystemSettings', $tab]));

            foreach ($this->pages() as $other => $otherTab) {
                $this->assertSame($other === $page, $other::canAccess(), "{$other} with only {$tab}");
            }
        }
    }

    public function test_a_page_answers_over_http_to_its_permission(): void
    {
        $this->actingAs($this->userWith(['View:SystemSettings', ScreenPermissions::SETTINGS_LETTERS_TAB]));

        $this->get(LettersSettings::getUrl())->assertSuccessful();
        $this->get(GeneralSettings::getUrl())->assertForbidden();
        $this->get(MailServerSettings::getUrl())->assertForbidden();
        $this->get(OfflineSettings::getUrl())->assertForbidden();
    }

    public function test_integrations_stay_super_admin_only(): void
    {
        $everything = $this->userWith(['View:SystemSettings', ...ScreenPermissions::all()]);
        $this->actingAs($everything);

        $this->assertFalse(IntegrationsSettings::canAccess());
        $this->get(IntegrationsSettings::getUrl())->assertForbidden();
        $this->assertTrue(GeneralSettings::canAccess());

        $this->actingAs($this->admin);
        $this->assertTrue(IntegrationsSettings::canAccess());
    }

    public function test_the_old_address_lands_on_the_first_page_the_user_may_open(): void
    {
        $this->get(SystemSettings::getUrl())->assertRedirect(GeneralSettings::getUrl());

        // Without General, the first they have.
        $this->actingAs($this->userWith(['View:SystemSettings', ScreenPermissions::SETTINGS_LETTERS_TAB, ScreenPermissions::SETTINGS_MAINTENANCE_TAB]));
        $this->get(SystemSettings::getUrl())->assertRedirect(LettersSettings::getUrl());
    }

    public function test_the_old_tab_names_land_on_what_they_became(): void
    {
        foreach ([
            'maintenance-offline' => OfflineSettings::class,
            'general-settings' => GeneralSettings::class,
            'email-settings' => MailServerSettings::class,
            'letters-emails' => LettersSettings::class,
            'notifications-announcements' => AnnouncementSettings::class,
            'integrations' => IntegrationsSettings::class,
        ] as $tab => $page) {
            $this->get(SystemSettings::getUrl().'?tab='.$tab)->assertRedirect($page::getUrl());
        }

        // A tab the user may not open falls back to the first page they may.
        $this->actingAs($this->userWith(['View:SystemSettings', ScreenPermissions::SETTINGS_EMAIL_TAB]));
        $this->get(SystemSettings::getUrl().'?tab=integrations')->assertRedirect(MailServerSettings::getUrl());
    }

    public function test_without_the_page_permission_the_old_address_is_closed(): void
    {
        $this->actingAs($this->userWith([ScreenPermissions::SETTINGS_GENERAL_TAB]));

        $this->get(SystemSettings::getUrl())->assertForbidden();
    }

    public function test_the_old_default_notify_toggles_are_gone(): void
    {
        Livewire::test(AnnouncementSettings::class)
            ->assertFormFieldDoesNotExist('default_notify_by_email')
            ->assertFormFieldDoesNotExist('default_notify_by_whatsapp');
    }

    public function test_general_saves_its_keys_and_keeps_the_language_in_the_env_file(): void
    {
        Livewire::test(GeneralSettings::class)
            ->fillForm([
                'app_name' => 'My Custom System',
                'company_name' => 'Custom Company',
                'company_phone' => '+971 4 328 7778',
                'company_whatsapp' => '+971 56 107 5965',
                'company_email' => 'office@example.ae',
                'currency_code' => 'USD',
                'app_locale' => 'en',
                'records_per_page' => 50,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('My Custom System', Setting::get('app_name'));
        $this->assertSame('Custom Company', Setting::get('company_name'));
        $this->assertSame('+971 4 328 7778', Setting::get('company_phone'));
        $this->assertSame('+971 56 107 5965', Setting::get('company_whatsapp'));
        $this->assertSame('office@example.ae', Setting::get('company_email'));
        $this->assertSame('USD', Setting::get('currency_code'));
        $this->assertSame('en', Setting::get('app_locale'));
        $this->assertEquals(50, Setting::get('records_per_page'));
        $this->assertStringContainsString('APP_LOCALE=en', File::get($this->envPath));
    }

    public function test_the_other_pages_save_their_keys_in_the_groups_they_always_had(): void
    {
        Livewire::test(OfflineSettings::class)
            ->fillForm(['app_offline' => true, 'offline_message' => 'Custom Offline Message', 'offline_allow_admins' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue(Setting::get('app_offline'));
        $this->assertSame('Custom Offline Message', Setting::get('offline_message'));
        $this->assertFalse(Setting::get('offline_allow_admins'));
        $this->assertSame('system', Setting::query()->where('key', 'app_offline')->value('group'));

        Livewire::test(MailServerSettings::class)
            ->fillForm(['mail_mailer' => 'log', 'mail_from_address' => 'system@test.com', 'mail_from_name' => 'System Tester'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('log', Setting::get('mail_mailer'));
        $this->assertSame('system@test.com', Setting::get('mail_from_address'));
        $this->assertSame('mail', Setting::query()->where('key', 'mail_from_address')->value('group'));

        Livewire::test(LettersSettings::class)
            ->fillForm(['letter_reference_format' => '{year}/{number}-{seq}', 'letter_cc_expert_types' => ['certified', 'assistant']])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('{year}/{number}-{seq}', Setting::get('letter_reference_format'));
        $this->assertSame(['certified', 'assistant'], Setting::get('letter_cc_expert_types'));
        $this->assertSame('general', Setting::query()->where('key', 'letter_reference_format')->value('group'));

        Livewire::test(AnnouncementSettings::class)
            ->fillForm([
                'show_system_announcement' => true,
                'system_announcement' => 'Maintenance on Friday',
                LiveRefresh::SETTING => 9,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        // Who gets the leave requests: with the letters and emails.
        Livewire::test(LettersSettings::class)
            ->fillForm(['leave_request_recipients' => ['hr@office.test']])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue(Setting::get('show_system_announcement'));
        $this->assertSame('Maintenance on Friday', Setting::get('system_announcement'));
        $this->assertSame(['hr@office.test'], Setting::get('leave_request_recipients'));
        $this->assertSame(9, LiveRefresh::configured());
        $this->assertSame('notifications', Setting::query()->where('key', 'system_announcement')->value('group'));
        $this->assertSame('general', Setting::query()->where('key', 'leave_request_recipients')->value('group'));
    }

    public function test_saving_a_page_writes_none_of_the_other_pages_keys(): void
    {
        // Everything the other pages hold, set first.
        foreach ([
            'app_name' => 'Kept name', 'currency_code' => 'EUR', 'mail_host' => 'smtp.kept.test', 'mail_from_name' => 'Kept sender',
            'letter_reference_format' => '{seq}/{year}', 'offline_message' => 'Kept message', 'system_announcement' => 'Kept announcement',
            LiveRefresh::SETTING => 7, Integrations::PUSHER_CLUSTER => 'eu', MatterReplyCollector::LOOK_BACK => 4,
        ] as $key => $value) {
            Setting::set($key, $value);
        }

        $keys = [
            GeneralSettings::class => ['app_name', 'company_name', 'company_phone', 'company_whatsapp', 'company_email', 'app_locale', 'currency_code', 'records_per_page', ...Branding::KEYS, OutlookCalendarService::INVITES, CompanyContact::NAME_AR, CompanyContact::ADDRESS_EN, CompanyContact::ADDRESS_AR, CompanyContact::LICENSE_NO, CalendarEventForm::SESSIONS_PREFIX],
            MailServerSettings::class => ['mail_sender_key', 'mail_mailer', 'mail_host', 'mail_port', 'mail_username', 'mail_password', 'mail_encryption', 'mail_from_address', 'mail_from_name'],
            LettersSettings::class => ['letter_reference_format', 'letter_cc_expert_types', EmailSendFields::DEFAULT_SENDER, Branding::EMAIL_PDF, Branding::EMAIL_PDF_FONT, 'leave_request_recipients'],
            OfflineSettings::class => ['app_offline', 'offline_message', 'offline_allow_admins'],
            AnnouncementSettings::class => [LiveRefresh::SETTING, 'system_announcement', 'show_system_announcement'],
            IntegrationsSettings::class => Integrations::KEYS,
            MailCollectingSettings::class => [MatterReplyCollector::LOOK_BACK, MatterReplyCollector::IGNORED],
        ];

        $changes = [
            GeneralSettings::class => ['company_name' => 'Changed company'],
            MailServerSettings::class => ['mail_mailer' => 'log'],
            LettersSettings::class => ['letter_reference_format' => '{year}-{seq}'],
            OfflineSettings::class => ['app_offline' => true, 'offline_message' => 'Changed message'],
            AnnouncementSettings::class => ['show_system_announcement' => true, 'system_announcement' => 'Changed announcement'],
            IntegrationsSettings::class => [Integrations::PUSHER_CLUSTER => 'ap2'],
            MailCollectingSettings::class => [MatterReplyCollector::LOOK_BACK => 6],
        ];

        $snapshot = fn (): array => Setting::query()->pluck('value', 'key')->all();

        foreach ($changes as $page => $fill) {
            $before = $snapshot();

            Livewire::test($page)->fillForm($fill)->call('save')->assertHasNoFormErrors();

            $after = $snapshot();
            $written = array_keys(array_filter($after, fn (mixed $value, string $key): bool => ! array_key_exists($key, $before) || $before[$key] !== $value, ARRAY_FILTER_USE_BOTH));

            $this->assertSame([], array_values(array_diff($written, $keys[$page])), "{$page} wrote another page's keys");
            $this->assertNotEmpty(array_intersect(array_keys($fill), $written), "{$page} did not save its own keys");
        }
    }

    public function test_the_integrations_page_saves_secrets_encrypted_and_keeps_blank_ones(): void
    {
        Livewire::test(IntegrationsSettings::class)
            ->fillForm([
                Integrations::PUSHER_APP => '9', Integrations::PUSHER_KEY => 'k', Integrations::PUSHER_SECRET => 's', Integrations::PUSHER_CLUSTER => 'eu',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('s', Integrations::get(Integrations::PUSHER_SECRET));
        $this->assertNotSame('s', Setting::query()->where('key', Integrations::PUSHER_SECRET)->value('value'));

        // How the mailboxes are read: with the mailboxes now.
        Livewire::test(MailCollectingSettings::class)
            ->fillForm([MatterReplyCollector::LOOK_BACK => 5, MatterReplyCollector::IGNORED => ['@spam.test']])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame(5, MatterReplyCollector::lookBackDays());
        $this->assertSame('mail', Setting::query()->where('key', MatterReplyCollector::LOOK_BACK)->value('group'));

        // Saved secrets don't come back to the page, and a blank one keeps the saved.
        Livewire::test(IntegrationsSettings::class)
            ->assertSet('data.'.Integrations::PUSHER_SECRET, null)
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('s', Integrations::get(Integrations::PUSHER_SECRET));

        // Nothing else was written by this page.
        $this->assertNull(Setting::query()->where('key', 'app_name')->value('value'));
    }

    public function test_the_smtp_password_is_kept_encrypted_and_never_sent_back(): void
    {
        Livewire::test(MailServerSettings::class)
            ->fillForm(['mail_mailer' => 'smtp', 'mail_password' => 'secret123', 'mail_from_address' => 'system@test.com', 'mail_from_name' => 'System'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertNotSame('secret123', Setting::query()->where('key', 'mail_password')->value('value'));
        $this->assertSame('secret123', Setting::mailPassword());

        // Not on the page; left empty, kept as it is.
        Livewire::test(MailServerSettings::class)
            ->assertFormSet(['mail_password' => null])
            ->fillForm(['mail_from_name' => 'Renamed'])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame('secret123', Setting::mailPassword());

        // Given to the mailer as typed.
        Setting::applyMailConfig();
        $this->assertSame('secret123', config('mail.mailers.smtp.password'));
    }

    public function test_can_trigger_send_test_email(): void
    {
        Mail::fake();

        Livewire::test(MailServerSettings::class)
            ->callAction('sendTestEmail', [
                'recipient' => 'target@example.com',
            ])
            ->assertHasNoErrors();
    }
}
