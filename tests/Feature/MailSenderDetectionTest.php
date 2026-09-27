<?php

namespace Tests\Feature;

use App\Filament\Mms\Resources\MailSenders\Pages\ManageMailSenders;
use App\Filament\Shared\Pages\SystemSettings;
use App\Models\MailSender;
use App\Models\Setting;
use App\Models\User;
use App\Services\Installer\EnvironmentFileWriter;
use App\Services\MMS\MailboxDetector;
use App\Services\MMS\SenderMailer;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Mockery;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Telling Microsoft 365 and cPanel mailboxes apart, and the system's own
 * emails (notifications) going out from a chosen sender.
 */
class MailSenderDetectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'mail.mailers.microsoft-graph.tenant_id' => 'tenant',
            'mail.mailers.microsoft-graph.client_id' => 'client',
            'mail.mailers.microsoft-graph.client_secret' => 'secret',
        ]);

        $this->actingAs(User::factory()->create()->assignRole(
            Role::firstOrCreate(['name' => config('filament-shield.super_admin.name', 'super_admin'), 'guard_name' => 'web'])
        ));
        Filament::setCurrentPanel('admin');
    }

    private function graphSays(int $status): void
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['access_token' => 'token']),
            'graph.microsoft.com/*' => Http::response(['id' => 'x'], $status),
        ]);
    }

    /**
     * @param  list<string>  $mx
     */
    private function detector(array $mx = []): MailboxDetector
    {
        $detector = Mockery::mock(MailboxDetector::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $detector->shouldReceive('mxHosts')->andReturn($mx);

        return $detector;
    }

    public function test_a_mailbox_found_in_microsoft_365_is_microsoft(): void
    {
        $this->graphSays(200);

        $found = $this->detector()->detect('noreply@jpaemirates.com');

        $this->assertSame(MailSender::MICROSOFT, $found['driver']);
        $this->assertSame(MailboxDetector::CERTAIN, $found['confidence']);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'users/noreply%40jpaemirates.com'));
    }

    public function test_a_mailbox_not_in_microsoft_365_is_cpanel_even_on_a_microsoft_domain(): void
    {
        // jpaemirates.com receives at Microsoft, yet iflas@ is on cPanel.
        $this->graphSays(404);

        $found = $this->detector(['jpaemirates-com.mail.protection.outlook.com'])->detect('iflas@jpaemirates.com');

        $this->assertSame(MailSender::SMTP, $found['driver']);
        $this->assertSame(MailboxDetector::CERTAIN, $found['confidence']);
        $this->assertSame('mail.jpaemirates.com', $found['host']);
        $this->assertSame(587, $found['port']);
    }

    public function test_without_microsoft_the_domains_mx_record_guesses(): void
    {
        $this->graphSays(403); // no User.Read.All permission

        $found = $this->detector(['jpaemirates-com.mail.protection.outlook.com'])->detect('info@jpaemirates.com');
        $this->assertSame(MailSender::MICROSOFT, $found['driver']);
        $this->assertSame(MailboxDetector::GUESS, $found['confidence']);

        config(['mail.mailers.microsoft-graph.client_id' => null]); // Graph not set up
        $found = $this->detector(['mail.example.com'])->detect('info@example.com');
        $this->assertSame(MailSender::SMTP, $found['driver']);
        $this->assertSame(MailboxDetector::GUESS, $found['confidence']);
        $this->assertSame('mail.example.com', $found['host']);
    }

    public function test_entering_the_address_fills_in_the_form(): void
    {
        $this->graphSays(404);

        Livewire::test(ManageMailSenders::class)
            ->mountAction('create')
            ->fillForm(['address' => 'haikala@jpaemirates.com'])
            ->assertSchemaStateSet([
                'driver' => MailSender::SMTP,
                'host' => 'mail.jpaemirates.com',
                'port' => 587,
                'username' => 'haikala@jpaemirates.com',
                'key' => 'haikala',
            ], 'mountedActionSchema0');
    }

    public function test_system_emails_go_out_from_the_chosen_sender(): void
    {
        MailSender::create(['key' => 'noreply', 'name' => 'JPA Emirates', 'address' => 'noreply@jpaemirates.com', 'driver' => MailSender::MICROSOFT]);
        Setting::set('mail_sender_key', 'noreply', 'mail');

        Setting::applyMailConfig();

        $this->assertSame('microsoft-graph', config('mail.default'));
        $this->assertSame('noreply@jpaemirates.com', config('mail.from.address'));

        // A notification-style email through the app's default mailer.
        config(['mail.mailers.microsoft-graph.transport' => 'array']);
        $sent = [];
        Event::listen(MessageSent::class, function (MessageSent $event) use (&$sent) {
            $sent[] = $event->message;
        });
        Mail::raw('New matter assigned', fn ($m) => $m->to('assistant@jpaemirates.com')->subject('New matter'));

        $this->assertSame('noreply@jpaemirates.com', $sent[0]->getFrom()[0]->getAddress());
    }

    public function test_a_missing_sender_falls_back_to_the_custom_settings(): void
    {
        Setting::set('mail_sender_key', 'deleted', 'mail');
        Setting::set('mail_mailer', 'smtp', 'mail');
        Setting::set('mail_from_address', 'custom@jpaemirates.com', 'mail');

        Setting::applyMailConfig();

        $this->assertSame('smtp', config('mail.default'));
        $this->assertSame('custom@jpaemirates.com', config('mail.from.address'));
    }

    public function test_system_settings_offer_the_senders(): void
    {
        MailSender::create(['key' => 'noreply', 'name' => 'JPA Emirates', 'address' => 'noreply@jpaemirates.com', 'driver' => MailSender::MICROSOFT]);

        // Each sender says where it lives.
        $this->assertSame('JPA Emirates <noreply@jpaemirates.com> — Microsoft 365', SenderMailer::options()['noreply']);

        // Saving writes APP_LOCALE to .env — a scratch copy, never the real one.
        $envPath = tempnam(sys_get_temp_dir(), 'env');
        $this->app->instance(EnvironmentFileWriter::class, new EnvironmentFileWriter($envPath));

        Livewire::test(SystemSettings::class)
            ->fillForm(['mail_sender_key' => 'noreply'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('noreply', Setting::get('mail_sender_key'));
        @unlink($envPath);
    }
}
