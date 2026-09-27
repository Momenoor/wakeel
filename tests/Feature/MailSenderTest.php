<?php

namespace Tests\Feature;

use App\Filament\Mms\Resources\MailSenders\MailSenderResource;
use App\Filament\Mms\Resources\MailSenders\Pages\ManageMailSenders;
use App\Models\MailSender;
use App\Models\User;
use App\Services\MMS\SenderMailer;
use App\Services\MMS\SentFolder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use ReflectionProperty;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class MailSenderTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<MessageSent> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'mail_senders.senders' => ['iflas' => ['name' => 'Iflas', 'address' => 'iflas@jpaemirates.com', 'host' => 'mail.example.com', 'port' => 587, 'username' => 'iflas@jpaemirates.com', 'password' => 'x', 'encryption' => 'tls']],
            'mail.mailers.microsoft-graph.tenant_id' => 'tenant',
            'mail.mailers.microsoft-graph.client_id' => 'client',
            'mail.mailers.microsoft-graph.client_secret' => 'secret',
        ]);

        Event::listen(MessageSent::class, fn (MessageSent $event) => $this->sent[] = $event);

        $this->actingAs(User::factory()->create()->assignRole(
            Role::firstOrCreate(['name' => config('filament-shield.super_admin.name', 'super_admin'), 'guard_name' => 'web'])
        ));
        Filament::setCurrentPanel('admin');
    }

    private function microsoft(string $key, string $address): MailSender
    {
        return MailSender::create(['key' => $key, 'name' => ucfirst($key), 'address' => $address, 'driver' => MailSender::MICROSOFT]);
    }

    public function test_senders_added_in_the_app_join_the_config_ones(): void
    {
        $this->microsoft('noreply', 'noreply@jpaemirates.com');
        MailSender::create(['key' => 'old', 'name' => 'Old', 'address' => 'old@x.com', 'driver' => MailSender::MICROSOFT, 'is_active' => false]);

        $this->assertSame(['noreply', 'iflas'], array_keys(SenderMailer::options()));
        $this->assertSame('Noreply <noreply@jpaemirates.com> — Microsoft 365', SenderMailer::options()['noreply']);
        $this->assertSame('Iflas <iflas@jpaemirates.com> — cPanel', SenderMailer::options()['iflas']);
        $this->assertTrue(SenderMailer::isMicrosoft(SenderMailer::sender('noreply')));
        $this->assertFalse(SenderMailer::isMicrosoft(SenderMailer::sender('iflas')));
    }

    public function test_a_microsoft_sender_goes_through_graph_as_its_own_mailbox(): void
    {
        $info = $this->microsoft('info', 'info@jpaemirates.com')->toSender();
        $noreply = $this->microsoft('noreply', 'noreply@jpaemirates.com')->toSender();

        $graphMailbox = function () {
            $this->assertSame('microsoft-graph', config('mail.default'));

            // The Graph client sends as the from-address it was built with.
            return (new ReflectionProperty(app('mail.microsoft-graph.client'), 'fromAddress'))->getValue(app('mail.microsoft-graph.client'));
        };

        $this->assertSame('info@jpaemirates.com', SenderMailer::using($info, $graphMailbox));
        // Rebuilt for the next sender, not the first one's client reused.
        $this->assertSame('noreply@jpaemirates.com', SenderMailer::using($noreply, $graphMailbox));
        // And the app's own mailer is back afterwards.
        $this->assertNotSame('microsoft-graph', config('mail.default'));
    }

    public function test_a_microsoft_mail_actually_leaves_from_that_address(): void
    {
        // Graph swapped for memory; the mailer choice and From are what count.
        config(['mail.mailers.microsoft-graph.transport' => 'array']);

        SenderMailer::using($this->microsoft('info', 'info@jpaemirates.com')->toSender(), fn () => Mail::raw('Hi', fn ($m) => $m->to('a@example.com')->subject('Hello')));

        $this->assertCount(1, $this->sent);
        $this->assertSame('info@jpaemirates.com', $this->sent[0]->message->getFrom()[0]->getAddress());
    }

    public function test_microsoft_mailboxes_keep_their_own_sent_items(): void
    {
        $this->microsoft('info', 'info@jpaemirates.com');

        // No IMAP login is attempted (it would fail without a password).
        app(SentFolder::class)->saveFor('info', "From: info@jpaemirates.com\r\n\r\nHi");
        $this->addToAssertionCount(1);
    }

    public function test_an_smtp_password_is_stored_encrypted(): void
    {
        MailSender::create(['key' => 'cpanel', 'name' => 'Cpanel', 'address' => 'x@jpaemirates.com', 'driver' => MailSender::SMTP,
            'host' => 'mail.jpaemirates.com', 'port' => 587, 'password' => 'p@ss$word']);

        $this->assertNotSame('p@ss$word', DB::table('mail_senders')->value('password'));
        $this->assertSame('p@ss$word', SenderMailer::sender('cpanel')['password']);
    }

    public function test_the_screen_adds_and_tests_a_sender(): void
    {
        Livewire::test(ManageMailSenders::class)
            ->callAction('create', [
                'driver' => MailSender::MICROSOFT,
                'address' => 'noreply@jpaemirates.com',
                'name' => 'JPA Emirates',
                'key' => 'noreply',
                'is_active' => true,
            ])
            ->assertHasNoActionErrors();

        $sender = MailSender::sole();
        $this->assertSame(MailSender::MICROSOFT, $sender->driver);

        // A code already used by a config sender is refused.
        Livewire::test(ManageMailSenders::class)
            ->callAction('create', ['driver' => MailSender::MICROSOFT, 'address' => 'i@x.com', 'name' => 'X', 'key' => 'iflas'])
            ->assertHasActionErrors(['key']);

        config(['mail.mailers.microsoft-graph.transport' => 'array']);
        Livewire::test(ManageMailSenders::class)
            ->callTableAction('test', $sender, ['to' => 'me@example.com'])
            ->assertNotified(__('Test email sent to :to', ['to' => 'me@example.com']));

        $this->assertSame('noreply@jpaemirates.com', $this->sent[0]->message->getFrom()[0]->getAddress());
        $this->get(MailSenderResource::getUrl())->assertSuccessful();
    }
}
