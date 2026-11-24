<?php

namespace Tests\Feature;

use App\Enums\DeliveryStatus;
use App\Filament\Mms\Resources\Matters\Pages\ViewMatter;
use App\Filament\Mms\Resources\Matters\RelationManagers\EmailsRelationManager;
use App\Models\MailSender;
use App\Models\Matter;
use App\Models\MatterEmail;
use App\Models\Setting;
use App\Models\User;
use App\Services\MMS\MessageTrace;
use App\Services\MMS\SenderMailer;
use App\Support\Integrations;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * What became of each email sent from a matter, read from Microsoft 365's message trace: at each address, the
 * worst of them on the matter's Emails tab — and why one was not delivered, in Microsoft's words.
 */
class MessageTraceTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $asked = [];

    private Matter $matter;

    private int $status = 200;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'mail.mailers.microsoft-graph' => ['transport' => 'microsoft-graph', 'tenant_id' => 'tenant', 'client_id' => 'client', 'client_secret' => 'secret'],
            'services.outlook' => ['tenant_id' => 'tenant', 'client_id' => 'client', 'client_secret' => 'secret'],
        ]);
        Cache::flush();
        Setting::set(Integrations::MS_TRACE, '1', 'integrations');
        Setting::clearCache();
        SenderMailer::forget();

        MailSender::create(['key' => 'office', 'name' => 'Office', 'address' => 'Office@jpa.ae', 'driver' => MailSender::MICROSOFT]);
        MailSender::create(['key' => 'cpanel', 'name' => 'cPanel', 'address' => 'info@jpa.ae', 'driver' => MailSender::SMTP, 'host' => 'mail.jpa.ae']);
        $this->matter = Matter::factory()->create();
    }

    private function sent(string $senderKey, string $messageId, array $to, $at): MatterEmail
    {
        return MatterEmail::create(['matter_id' => $this->matter->id, 'direction' => MatterEmail::SENT, 'sender_key' => $senderKey, 'message_id' => $messageId, 'subject' => 'Report', 'to' => $to, 'at' => $at]);
    }

    /** @var array<int, array<string, mixed>> what Microsoft answers, by the filter's field */
    private array $traces = [];

    private bool $faked = false;

    /**
     * Microsoft's answer from now on: each trace given to the request asking for its mailbox as sender or as
     * recipient (a trace with 'as' => 'recipientAddress' for one received; by sender otherwise).
     *
     * @param  array<int, array<string, mixed>>  $traces
     */
    private function microsoft(array $traces, int $status = 200): void
    {
        $this->traces = $traces;
        $this->status = $status;

        if ($this->faked) {
            return;
        }

        $this->faked = true;
        Http::fake(function (Request $request) {
            $this->asked[] = urldecode($request->url());
            $status = $this->status;
            $field = str_contains(urldecode($request->url()), 'recipientAddress eq') ? 'recipientAddress' : 'senderAddress';
            $traces = array_values(array_filter($this->traces, fn (array $trace): bool => ($trace['as'] ?? 'senderAddress') === $field));

            return match (true) {
                str_contains($request->url(), 'login.microsoftonline.com') => Http::response(['access_token' => 'token']),
                $status !== 200 => Http::response(['error' => ['message' => 'Access is denied']], $status),
                str_contains($request->url(), 'getDetailsByRecipient') => Http::response(['value' => [
                    ['dateTime' => '2026-11-24T08:00:00Z', 'event' => 'Receive', 'description' => 'The message was received.'],
                    ['dateTime' => '2026-11-24T08:00:05Z', 'event' => 'Fail', 'description' => '550 5.1.1 The email account that you tried to reach does not exist.'],
                ]]),
                default => Http::response(['value' => $traces]),
            };
        });
    }

    public function test_each_address_is_known_and_the_email_shows_the_worst_with_microsofts_reason(): void
    {
        $email = $this->sent('office', 'abc@jpa.ae', ['court@gov.ae', 'nobody@gov.ae'], now()->subMinutes(30));
        $this->microsoft([
            // Microsoft's Message-ID has its brackets; ours is kept without them.
            ['id' => 't1', 'messageId' => '<abc@jpa.ae>', 'recipientAddress' => 'Court@gov.ae', 'status' => 'delivered', 'receivedDateTime' => '2026-11-24T08:00:00Z'],
            ['id' => 't2', 'messageId' => '<abc@jpa.ae>', 'recipientAddress' => 'nobody@gov.ae', 'status' => 'failed', 'receivedDateTime' => '2026-11-24T08:00:00Z'],
            // Another email of the mailbox, not one of a matter: left alone.
            ['id' => 't3', 'messageId' => '<other@jpa.ae>', 'recipientAddress' => 'x@y.com', 'status' => 'delivered', 'receivedDateTime' => '2026-11-24T08:00:00Z'],
        ]);

        $this->assertSame(['checked' => 1, 'updated' => 1], app(MessageTrace::class)->run());

        $email->refresh();
        $this->assertSame(DeliveryStatus::FAILED, $email->delivery_status);
        $this->assertSame('delivered', $email->delivery['court@gov.ae']['status']);
        $this->assertStringContainsString('550 5.1.1', $email->delivery['nobody@gov.ae']['detail']);

        // Asked by the mailbox, for the days since the email went.
        $list = collect($this->asked)->first(fn (string $url): bool => str_contains($url, 'messageTraces?'));
        $this->assertStringContainsString("senderAddress eq 'office@jpa.ae' and receivedDateTime ge ", $list);

        // Known at every address: not asked for again.
        $this->asked = [];
        $this->assertSame(['checked' => 0, 'updated' => 0], app(MessageTrace::class)->run());
        $this->assertSame([], $this->asked);
    }

    public function test_one_still_on_its_way_is_asked_again_and_one_never_traced_is_given_up_on(): void
    {
        $onItsWay = $this->sent('office', 'way@jpa.ae', ['court@gov.ae'], now()->subMinutes(10));
        $cpanel = $this->sent('cpanel', 'cp@jpa.ae', ['court@gov.ae'], now()->subDays(3));
        $recent = $this->sent('cpanel', 'cp2@jpa.ae', ['court@gov.ae'], now()->subHour());
        $this->microsoft([['id' => 't1', 'messageId' => '<way@jpa.ae>', 'recipientAddress' => 'court@gov.ae', 'status' => 'pending', 'receivedDateTime' => '2026-11-24T08:00:00Z']]);

        app(MessageTrace::class)->run();

        $this->assertSame(DeliveryStatus::PENDING, $onItsWay->fresh()->delivery_status);
        // Sent through another server: nothing to find at Microsoft — given up on after two days, still looked for before.
        $this->assertSame(DeliveryStatus::NONE, $cpanel->fresh()->delivery_status);
        $this->assertNull($recent->fresh()->delivery_status);
        $this->assertCount(1, collect($this->asked)->filter(fn (string $url): bool => str_contains($url, 'messageTraces?')));

        $this->microsoft([['id' => 't1', 'messageId' => '<way@jpa.ae>', 'recipientAddress' => 'court@gov.ae', 'status' => 'delivered', 'receivedDateTime' => '2026-11-24T08:01:00Z']]);
        app(MessageTrace::class)->run();
        $this->assertSame(DeliveryStatus::DELIVERED, $onItsWay->fresh()->delivery_status);
    }

    public function test_an_email_received_is_traced_at_our_mailbox_too(): void
    {
        $received = MatterEmail::create(['matter_id' => $this->matter->id, 'direction' => MatterEmail::RECEIVED, 'sender_key' => 'office', 'message_id' => 'court-1@gov.ae', 'subject' => 'Hearing', 'from' => 'court@gov.ae', 'at' => now()->subMinutes(20)]);
        $this->microsoft([
            ['as' => 'recipientAddress', 'id' => 't9', 'messageId' => '<court-1@gov.ae>', 'recipientAddress' => 'office@jpa.ae', 'status' => 'filteredAsSpam', 'receivedDateTime' => '2026-11-24T07:00:00Z'],
        ]);

        app(MessageTrace::class)->run();

        // Looked for by our mailbox as its recipient — and found taken for spam there.
        $this->assertTrue(collect($this->asked)->contains(fn (string $url): bool => str_contains($url, "recipientAddress eq 'office@jpa.ae'")));
        $this->assertSame(DeliveryStatus::SPAM, $received->fresh()->delivery_status);
        $this->assertSame('spam', $received->fresh()->delivery['office@jpa.ae']['status']);
    }

    public function test_a_refusal_says_what_to_grant_once_and_waits(): void
    {
        $this->sent('office', 'abc@jpa.ae', ['court@gov.ae'], now()->subMinutes(30));
        $this->microsoft([], 403);

        app(MessageTrace::class)->run();
        $this->asked = [];
        app(MessageTrace::class)->run();

        // Not asked again while it waits.
        $this->assertSame([], $this->asked);
    }

    public function test_off_by_default_asks_nothing_and_shows_no_column(): void
    {
        Setting::set(Integrations::MS_TRACE, '0', 'integrations');
        Setting::clearCache();
        $this->sent('office', 'abc@jpa.ae', ['court@gov.ae'], now()->subMinutes(30));
        $this->microsoft([]);

        $this->assertSame(['checked' => 0, 'updated' => 0], app(MessageTrace::class)->run());
        $this->assertSame([], $this->asked);
        $this->assertFalse(MessageTrace::enabled());
    }

    public function test_the_emails_tab_shows_the_delivery_with_each_address_in_its_tooltip(): void
    {
        Filament::setCurrentPanel('mms');
        $this->actingAs(User::factory()->create()->assignRole(Role::firstOrCreate(['name' => config('filament-shield.super_admin.name', 'super_admin'), 'guard_name' => 'web'])));
        $email = $this->sent('office', 'abc@jpa.ae', ['court@gov.ae'], now()->subMinutes(30));
        $email->forceFill(['delivery_status' => 'failed', 'delivery' => ['court@gov.ae' => ['status' => 'failed', 'at' => '2026-11-24T08:00:00Z', 'detail' => '550 5.1.1 unknown']]])->save();

        Livewire::test(EmailsRelationManager::class, ['ownerRecord' => $this->matter, 'pageClass' => ViewMatter::class])
            ->assertTableColumnVisible('delivery_status')
            ->assertSee(DeliveryStatus::FAILED->getLabel())
            ->assertSee('550 5.1.1 unknown');
    }
}
