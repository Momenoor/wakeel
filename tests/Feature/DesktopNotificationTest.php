<?php

namespace Tests\Feature;

use App\Filament\Mms\Pages\Chat;
use App\Livewire\NotificationPoller;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The system's notifications, also shown by the browser when Wakeel is in
 * a background tab — once the user has allowed desktop notifications.
 */
class DesktopNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Chat has its own page permission; these users hold it.
        Gate::before(fn ($user, string $ability) => $ability === 'View:Chat' ? true : null);
    }

    public function test_a_new_notification_is_sent_to_the_browser_as_well(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Notification::make()
            ->title('Matter 2026/123 assigned')
            ->body('<p>You were assigned to <strong>2026/123</strong>.</p>')
            ->actions([Action::make('open')->url('https://wakeel.test/mms/matters/5')])
            ->sendToDatabase($user);

        $notification = $user->notifications()->sole();

        Livewire::test(NotificationPoller::class)
            ->call('checkNotifications')
            ->assertDispatched('wakeel-desktop-notification',
                id: $notification->id,
                title: 'Matter 2026/123 assigned',
                body: 'You were assigned to 2026/123.',
                url: 'https://wakeel.test/mms/matters/5',
            );

        // Shown once: read after the first time.
        $this->assertNotNull($notification->fresh()->read_at);
        $this->actingAs($user->fresh());
        Livewire::test(NotificationPoller::class)
            ->call('checkNotifications')
            ->assertNotDispatched('wakeel-desktop-notification');
    }

    public function test_the_prompt_opens_on_the_first_page_after_every_login(): void
    {
        $user = User::factory()->create(['password' => bcrypt('secret-password')]);
        Filament::setCurrentPanel('mms');

        $this->assertTrue(auth()->attempt(['email' => $user->email, 'password' => 'secret-password']));

        $this->get(Chat::getUrl())->assertSuccessful()->assertSee('promptAfterLogin: true', false);
        // Only the first page after it.
        $this->get(Chat::getUrl())->assertSuccessful()->assertSee('promptAfterLogin: false', false);

        // And again at the next login.
        auth()->logout();
        $this->assertTrue(auth()->attempt(['email' => $user->email, 'password' => 'secret-password']));
        $this->get(Chat::getUrl())->assertSee('promptAfterLogin: true', false);
    }

    public function test_the_panel_offers_to_turn_them_on(): void
    {
        $this->actingAs(User::factory()->create());
        Filament::setCurrentPanel('mms');

        $this->get(Chat::getUrl())
            ->assertSuccessful()
            ->assertSee('Enable desktop notifications')
            ->assertSee('wakeel-desktop-notification.window', false)
            ->assertSee('Notification.requestPermission', false);
    }

    public function test_a_tab_does_not_repeat_what_web_push_already_shows(): void
    {
        $this->actingAs(User::factory()->create());
        Filament::setCurrentPanel('mms');

        $this->get(Chat::getUrl())
            ->assertSuccessful()
            // With Web Push on, the service worker shows it — the tab does not.
            ->assertSee('this.pushActive = true;', false)
            ->assertSee("state !== 'granted' || pushActive ||", false)
            // Without push, only one open tab shows it.
            ->assertSee("'wakeel-desktop:' + n.id", false);
    }

    public function test_notifications_and_chat_play_a_sound_that_can_be_turned_off(): void
    {
        $this->actingAs(User::factory()->create());
        Filament::setCurrentPanel('mms');

        $this->get(Chat::getUrl())
            ->assertSuccessful()
            // Plays on the same event as the desktop notification.
            ->assertSee("window.addEventListener('wakeel-desktop-notification'", false)
            ->assertSee("id.startsWith('chat-') ? 'chat' : 'notification'", false)
            // The office's own sound file, for notifications and (with no
            // chat.mp3) for chat too.
            ->assertSee('notification.mp3', false)
            // The on/off button, remembered per browser.
            ->assertSee('Turn notification sounds off')
            ->assertSee("localStorage.setItem('wakeel-sound'", false);
    }
}
