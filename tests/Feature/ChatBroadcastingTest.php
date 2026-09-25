<?php

namespace Tests\Feature;

use App\Filament\Mms\Pages\Chat;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The panels never load resources/js/app.js, so live chat only works when
 * Filament itself starts window.Echo — from config/filament.php, filled
 * from .env (BROADCAST_CONNECTION=pusher + PUSHER_*) at runtime.
 */
class ChatBroadcastingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());

        Filament::setCurrentPanel('admin');
    }

    public function test_the_panel_starts_echo_with_the_pusher_key(): void
    {
        config(['filament.broadcasting.echo' => [
            'broadcaster' => 'pusher',
            'key' => 'public-key-123',
            'cluster' => 'ap2',
            'forceTLS' => true,
            'authEndpoint' => 'https://example.com/wakeel/broadcasting/auth',
        ]]);

        $this->get(Chat::getUrl())
            ->assertSuccessful()
            ->assertSee('window.Echo = new window.EchoFactory', false)
            ->assertSee('public-key-123', false)
            ->assertSee('echo-private:App.Models.User.'.auth()->id(), false);
    }

    public function test_without_a_broadcaster_the_panel_does_not_start_echo(): void
    {
        config(['filament.broadcasting.echo' => null]);

        $this->get(Chat::getUrl())
            ->assertSuccessful()
            ->assertDontSee('window.Echo = new window.EchoFactory', false);
    }

    public function test_a_user_can_only_join_their_own_private_channel(): void
    {
        config(['broadcasting.default' => 'pusher', 'broadcasting.connections.pusher' => [
            'driver' => 'pusher',
            'key' => 'public-key-123',
            'secret' => 'secret',
            'app_id' => '1',
            'options' => ['cluster' => 'ap2', 'useTLS' => true],
        ]]);
        // Channel rules belong to the broadcaster they were registered on —
        // the one booted before the switch to Pusher above.
        require base_path('routes/channels.php');

        $own = 'private-App.Models.User.'.auth()->id();

        $this->post('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => $own])
            ->assertSuccessful()
            ->assertJsonStructure(['auth']);

        $this->post('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => 'private-App.Models.User.999999'])
            ->assertForbidden();

        // The "who's online" presence channel, open to every signed-in user.
        $presence = $this->post('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => 'presence-online'])
            ->assertSuccessful()
            ->json();

        $this->assertSame(auth()->id(), json_decode($presence['channel_data'], true)['user_info']['id']);
    }
}
