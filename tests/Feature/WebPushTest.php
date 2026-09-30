<?php

namespace Tests\Feature;

use App\Filament\Mms\Pages\Chat;
use App\Models\PushSubscription;
use App\Models\Setting;
use App\Models\User;
use App\Services\Push\VapidKeys;
use App\Services\Push\WebPushSender;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Minishlink\WebPush\VAPID;
use Tests\TestCase;

/**
 * Web Push: system notifications delivered to the browsers and phones a
 * user allowed, even with no Wakeel tab open.
 */
class WebPushTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Chat has its own page permission; these users hold it.
        Gate::before(fn ($user, string $ability) => $ability === 'View:Chat' ? true : null);
    }

    /**
     * Push encryption needs OpenSSL EC keys; PHP on Windows only makes them
     * with its openssl.cnf pointed at.
     */
    private function requireEcKeys(): void
    {
        $canMake = fn () => @openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]) !== false;

        if (! $canMake()) {
            $cnf = dirname(PHP_BINARY).DIRECTORY_SEPARATOR.'extras'.DIRECTORY_SEPARATOR.'ssl'.DIRECTORY_SEPARATOR.'openssl.cnf';
            if (is_file($cnf)) {
                putenv('OPENSSL_CONF='.$cnf);
            }
        }

        if (! $canMake()) {
            $this->markTestSkipped('OpenSSL on this machine cannot make EC keys.');
        }
    }

    private function subscribe(User $user, string $endpoint = 'https://fcm.googleapis.com/fcm/send/abc', bool $realKeys = false): PushSubscription
    {
        return PushSubscription::create([
            'user_id' => $user->id,
            'endpoint' => $endpoint,
            'endpoint_hash' => PushSubscription::hashEndpoint($endpoint),
            // A real P-256 public key and auth secret, as a browser gives
            // them, when the push is really encrypted.
            'public_key' => $realKeys ? VAPID::createVapidKeys()['publicKey'] : 'BPlaceholderKey',
            'auth_token' => rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '='),
            'content_encoding' => 'aes128gcm',
        ]);
    }

    public function test_the_page_records_this_browser_for_the_signed_in_user(): void
    {
        $payload = [
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/xyz',
            'keys' => ['p256dh' => 'BPublicKey', 'auth' => 'secret'],
            'contentEncoding' => 'aes128gcm',
        ];

        $this->postJson('/push/subscriptions', $payload)->assertUnauthorized();

        $amr = User::factory()->create();
        $this->actingAs($amr)->postJson('/push/subscriptions', $payload)->assertNoContent();
        $this->actingAs($amr)->postJson('/push/subscriptions', $payload)->assertNoContent();

        $this->assertSame(1, PushSubscription::count());
        $this->assertSame($amr->id, PushSubscription::sole()->user_id);

        // The same browser signed in by someone else moves to them.
        $nahla = User::factory()->create();
        $this->actingAs($nahla)->postJson('/push/subscriptions', $payload)->assertNoContent();
        $this->assertSame($nahla->id, PushSubscription::sole()->user_id);

        // Only https push services are accepted.
        $this->actingAs($nahla)->postJson('/push/subscriptions', [...$payload, 'endpoint' => 'http://evil.test/x'])->assertUnprocessable();

        $this->actingAs($nahla)->deleteJson('/push/subscriptions', ['endpoint' => $payload['endpoint']])->assertNoContent();
        $this->assertSame(0, PushSubscription::count());
    }

    public function test_a_database_notification_is_pushed_to_the_users_devices(): void
    {
        // Sent after the response (defer); run it straight away here.
        $this->withoutDefer();
        $user = User::factory()->create();
        $this->subscribe($user);

        $sent = [];
        $this->app->instance(WebPushSender::class, new class($sent) extends WebPushSender
        {
            public function __construct(private array &$sent) {}

            public function sendToUser(int $userId, array $payload): int
            {
                $this->sent[] = [$userId, $payload];

                return 1;
            }
        });

        Notification::make()
            ->title('Matter 2026/123 assigned')
            ->body('<p>You were assigned to <b>2026/123</b>.</p>')
            ->actions([Action::make('open')->url('https://wakeel.test/mms/matters/5')])
            ->sendToDatabase($user);

        $this->assertCount(1, $sent);
        [$userId, $payload] = $sent[0];
        $this->assertSame($user->id, $userId);
        $this->assertSame('Matter 2026/123 assigned', $payload['title']);
        $this->assertSame('You were assigned to 2026/123.', $payload['body']);
        $this->assertSame('https://wakeel.test/mms/matters/5', $payload['url']);
        $this->assertSame('wakeel-'.$user->notifications()->sole()->id, $payload['tag']);
    }

    public function test_nothing_is_pushed_to_a_user_without_devices(): void
    {
        $this->withoutDefer();
        $user = User::factory()->create();
        $this->app->instance(WebPushSender::class, new class extends WebPushSender
        {
            public function sendToUser(int $userId, array $payload): int
            {
                throw new \LogicException('should not be called');
            }
        });

        Notification::make()->title('Hello')->sendToDatabase($user);

        $this->assertSame(1, $user->notifications()->count());
    }

    public function test_the_sender_delivers_and_forgets_devices_that_are_gone(): void
    {
        $this->requireEcKeys();

        $user = User::factory()->create();
        $this->subscribe($user, 'https://push.test/delivered', realKeys: true);
        $this->subscribe($user, 'https://push.test/gone', realKeys: true);
        $this->subscribe($user, 'https://push.test/down', realKeys: true);

        $requests = [];
        $mock = new MockHandler([new Response(201), new Response(410), new Response(500)]);
        $stack = HandlerStack::create($mock);
        $stack->push(function (callable $handler) use (&$requests) {
            return function ($request, $options) use ($handler, &$requests) {
                $requests[] = (string) $request->getUri();

                return $handler($request, $options);
            };
        });

        $sent = (new WebPushSender(new Client(['handler' => $stack])))->sendToUser($user->id, ['title' => 'Hi', 'body' => 'x']);

        $this->assertSame(1, $sent);
        $this->assertCount(3, $requests);
        // 410 Gone: that browser unsubscribed — removed; a failing service is kept.
        $this->assertEqualsCanonicalizing(
            ['https://push.test/delivered', 'https://push.test/down'],
            PushSubscription::pluck('endpoint')->all(),
        );
    }

    public function test_keys_are_made_once_and_the_private_key_is_kept_encrypted(): void
    {
        $this->requireEcKeys();
        config(['services.webpush.public_key' => null, 'services.webpush.private_key' => null]);

        $first = VapidKeys::get();
        $again = VapidKeys::get();

        $this->assertSame($first, $again);
        $stored = Setting::get('webpush_private_key');
        $this->assertNotSame($first['private'], $stored);
        $this->assertStringNotContainsString($first['private'], (string) $stored);
    }

    public function test_the_panel_serves_the_worker_and_the_manifest(): void
    {
        $this->actingAs(User::factory()->create());
        Filament::setCurrentPanel('mms');

        $this->get(Chat::getUrl())
            ->assertSuccessful()
            ->assertSee('push-sw.js', false)
            ->assertSee('rel="manifest"', false);

        $this->get('/manifest.webmanifest')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/manifest+json')
            ->assertJsonPath('display', 'standalone');

        $this->assertFileExists(public_path('push-sw.js'));
    }
}
