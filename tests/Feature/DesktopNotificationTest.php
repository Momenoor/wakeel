<?php

namespace Tests\Feature;

use App\Filament\Mms\Pages\Chat;
use App\Livewire\NotificationPoller;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The system's notifications, also shown by the browser when Wakeel is in
 * a background tab — once the user has allowed desktop notifications.
 */
class DesktopNotificationTest extends TestCase
{
    use RefreshDatabase;

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
}
