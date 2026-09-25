<?php

namespace Tests\Feature;

use App\Events\NotificationsUpdated;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * A new database notification pings the user's open tabs over Pusher, so
 * the bell and toasts don't have to poll for it every 10 seconds.
 */
class NotificationBroadcastTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutDefer();
    }

    public function test_a_database_notification_pings_the_user_over_pusher(): void
    {
        config(['broadcasting.default' => 'pusher']);
        Event::fake([NotificationsUpdated::class]);

        $user = User::factory()->create();

        Notification::make()->title('Lease renewed')->sendToDatabase($user);

        Event::assertDispatched(NotificationsUpdated::class, fn ($event) => $event->userId === $user->id);
        $this->assertSame('private-App.Models.User.'.$user->id, (new NotificationsUpdated($user->id))->broadcastOn()->name);
        $this->assertSame('database-notifications.sent', (new NotificationsUpdated($user->id))->broadcastAs());
    }

    public function test_without_a_live_broadcaster_nothing_is_sent(): void
    {
        config(['broadcasting.default' => 'log']);
        Event::fake([NotificationsUpdated::class]);

        Notification::make()->title('Lease renewed')->sendToDatabase(User::factory()->create());

        Event::assertNotDispatched(NotificationsUpdated::class);
    }

    public function test_a_broadcast_failure_never_breaks_the_notification(): void
    {
        // Pusher selected but unreachable/misconfigured.
        config(['broadcasting.default' => 'pusher', 'broadcasting.connections.pusher' => [
            'driver' => 'pusher', 'key' => 'k', 'secret' => 's', 'app_id' => '1',
            'options' => ['host' => '127.0.0.1', 'port' => 9, 'scheme' => 'http', 'useTLS' => false],
            'client_options' => ['timeout' => 1],
        ]]);

        $user = User::factory()->create();

        Notification::make()->title('Lease renewed')->sendToDatabase($user);

        $this->assertSame(1, $user->notifications()->count());
    }
}
