<?php

namespace Tests\Feature;

use App\Filament\Mms\Pages\Auth\CustomLogin;
use App\Models\User;
use BezhanSalleh\FilamentShield\Support\Utils;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * "Remember me" keeps a user signed in on each device they ticked it on — signing out on one ends
 * that one only, not the others.
 */
class RememberMeTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        $user = User::factory()->create(['email' => 'user@example.com', 'password' => bcrypt('right-password')]);
        $user->assignRole(Role::firstOrCreate(['name' => Utils::getSuperAdminName(), 'guard_name' => 'web']));

        return $user;
    }

    /** The device's remembered sign-in, as its cookie holds it. */
    private function remembered(User $user): string
    {
        $user->refresh();

        return $user->id.'|'.$user->remember_token.'|'.Auth::guard('web')->hashPasswordForCookie($user->password);
    }

    /** A request from a device whose session is gone (the browser closed), with only its cookie. */
    private function comeBack(string $cookie): TestResponse
    {
        $this->app['auth']->forgetGuards();
        $this->flushSession();

        return $this->withCookie(Auth::guard('web')->getRecallerName(), $cookie)->get('/mms');
    }

    public function test_signing_in_with_remember_me_keeps_the_device_signed_in(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('mms'));
        $this->user();

        Livewire::test(CustomLogin::class)
            ->fillForm(['login' => 'user@example.com', 'password' => 'right-password', 'remember' => true])
            ->call('authenticate')
            ->assertHasNoFormErrors();

        $this->assertNotNull(Cookie::queued(Auth::guard('web')->getRecallerName()));
    }

    public function test_signing_out_on_one_device_keeps_the_others_remembered(): void
    {
        $user = $this->user();

        // The office PC, remembered.
        Auth::guard('web')->login($user, true);
        $pc = $this->remembered($user);
        $token = $user->remember_token;

        // The phone signs out.
        $this->actingAs($user)->post(Filament::getPanel('mms')->getLogoutUrl())->assertRedirect();
        $this->assertGuest();
        $this->assertSame($token, $user->fresh()->remember_token);

        // The PC, its session long gone, is still signed in.
        $this->comeBack($pc)->assertOk();
        $this->assertAuthenticatedAs($user);
    }
}
