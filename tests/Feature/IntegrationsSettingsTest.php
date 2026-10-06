<?php

namespace Tests\Feature;

use App\Filament\Shared\Pages\SystemSettings;
use App\Models\Setting;
use App\Models\User;
use App\Observers\LeaveRequestObserver;
use App\Services\Installer\EnvironmentFileWriter;
use App\Support\Integrations;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Each office's own outside services, from System Settings → Integrations:
 * on when filled in (over .env), off when not, secrets kept encrypted.
 */
class IntegrationsSettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('mms'));
        Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        $this->admin = User::factory()->create(['email' => 'owner@office.test']);
        $this->admin->assignRole('super-admin');
        $this->actingAs($this->admin);
    }

    public function test_nothing_set_means_everything_is_off(): void
    {
        config([
            'services.outlook.tenant_id' => null, 'services.outlook.client_id' => null, 'services.outlook.client_secret' => null,
            'mail.mailers.microsoft-graph.tenant_id' => null, 'mail.mailers.microsoft-graph.client_id' => null, 'mail.mailers.microsoft-graph.client_secret' => null,
            'services.cron.token' => null, 'services.whatsapp.token' => null, 'services.whatsapp.phone_id' => null,
            'queue.default' => 'database',
        ]);

        Integrations::apply();

        $this->assertFalse(Integrations::microsoftOn());
        $this->assertFalse(Integrations::pusherOn());
        $this->assertFalse(Integrations::cronOn());
        $this->assertFalse(Integrations::whatsappOn());
        // No cron to run the queue: work is done at once.
        $this->assertSame('sync', config('queue.default'));
    }

    public function test_saved_settings_switch_each_service_on_with_secrets_encrypted(): void
    {
        config(['queue.default' => 'database']);
        $token = str_repeat('t', 40);

        Integrations::save([
            Integrations::MS_TENANT => 'tenant-1', Integrations::MS_CLIENT => 'client-1', Integrations::MS_SECRET => 'ms-secret', Integrations::MS_CALENDAR => 'calendar@office.test',
            Integrations::PUSHER_APP => '123', Integrations::PUSHER_KEY => 'pusher-key', Integrations::PUSHER_SECRET => 'pusher-secret', Integrations::PUSHER_CLUSTER => 'ap2',
            Integrations::CRON_TOKEN => $token,
            Integrations::WHATSAPP_PHONE => '555', Integrations::WHATSAPP_TOKEN => 'wa-token',
        ]);

        // Secrets never sit in the database as typed.
        $this->assertNotSame('ms-secret', DB::table('settings')->where('key', Integrations::MS_SECRET)->value('value'));
        $this->assertSame('ms-secret', Integrations::get(Integrations::MS_SECRET));

        Integrations::apply();

        $this->assertTrue(Integrations::microsoftOn());
        $this->assertSame('calendar@office.test', config('services.outlook.user_email'));
        $this->assertSame('client-1', config('mail.mailers.microsoft-graph.client_id'));

        $this->assertTrue(Integrations::pusherOn());
        $this->assertSame('pusher', config('broadcasting.default'));
        $this->assertSame(['broadcaster' => 'pusher', 'key' => 'pusher-key', 'cluster' => 'ap2', 'forceTLS' => true], config('filament.broadcasting.echo'));

        $this->assertTrue(Integrations::cronOn());
        $this->assertSame('database', config('queue.default'));
        $this->get(route('cron.run', ['token' => 'wrong']))->assertNotFound();

        $this->assertTrue(Integrations::whatsappOn());
        $this->assertSame('555', config('services.whatsapp.phone_id'));

        // A blank secret on a later save keeps the saved one.
        Integrations::save([Integrations::MS_SECRET => '', Integrations::MS_TENANT => 'tenant-2']);
        $this->assertSame('ms-secret', Integrations::get(Integrations::MS_SECRET));
        $this->assertSame('tenant-2', Integrations::get(Integrations::MS_TENANT));
    }

    public function test_the_super_admin_sets_them_in_system_settings(): void
    {
        // Saving writes APP_LOCALE to .env — never the real one.
        $envPath = sys_get_temp_dir().'/integrations-test-'.uniqid().'.env';
        file_put_contents($envPath, "APP_LOCALE=en\n");
        $this->app->instance(EnvironmentFileWriter::class, new EnvironmentFileWriter($envPath));

        Livewire::test(SystemSettings::class)
            ->assertSee(__('Integrations'))
            ->fillForm([
                Integrations::PUSHER_APP => '9', Integrations::PUSHER_KEY => 'k', Integrations::PUSHER_SECRET => 's', Integrations::PUSHER_CLUSTER => 'eu',
                'mail_mailer' => 'log',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('s', Integrations::get(Integrations::PUSHER_SECRET));
        $this->assertNotSame('s', Setting::query()->where('key', Integrations::PUSHER_SECRET)->value('value'));

        // Saved secrets don't come back to the page.
        Livewire::test(SystemSettings::class)->assertSet('data.'.Integrations::PUSHER_SECRET, null);
    }

    public function test_leave_requests_go_to_the_set_addresses_or_whoever_approves_leave(): void
    {
        $approver = User::factory()->create(['email' => 'hr@office.test']);
        $approver->givePermissionTo(Permission::firstOrCreate(['name' => 'Approve:LeaveRequest', 'guard_name' => 'web']));
        User::factory()->create(['email' => 'staff@office.test']);

        $this->assertEqualsCanonicalizing(['owner@office.test', 'hr@office.test'], LeaveRequestObserver::recipients());

        Setting::set('leave_request_recipients', ['manager@office.test', 'not-an-email']);
        $this->assertSame(['manager@office.test'], LeaveRequestObserver::recipients());
    }
}
