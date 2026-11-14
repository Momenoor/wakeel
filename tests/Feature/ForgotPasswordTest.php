<?php

namespace Tests\Feature;

use App\Filament\Mms\Pages\Auth\CustomLogin;
use App\Filament\Mms\Pages\Auth\CustomRequestPasswordReset;
use App\Models\User;
use Filament\Auth\Notifications\ResetPassword as ResetPasswordNotification;
use Filament\Auth\Pages\PasswordReset\ResetPassword;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * "Forgot password?" on the sign-in page: a link by email, asked for by username or email address.
 */
class ForgotPasswordTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('mms'));
        config(['mail.default' => 'smtp']);
        Notification::fake();
    }

    public function test_the_sign_in_page_offers_it(): void
    {
        Livewire::test(CustomLogin::class)->assertSeeHtml(Filament::getRequestPasswordResetUrl());
    }

    public function test_the_link_is_sent_for_a_username_or_an_email_address(): void
    {
        $user = User::factory()->create(['name' => 'sara', 'email' => 'sara@office.test']);

        Livewire::test(CustomRequestPasswordReset::class)
            ->fillForm(['email' => 'sara'])
            ->call('request')
            ->assertNotified(__('Check your email'));

        Notification::assertSentTo($user, ResetPasswordNotification::class, fn (ResetPasswordNotification $sent): bool => str_contains($sent->url, 'password-reset/reset'));

        // By the address too — once the minute between two requests has passed.
        $this->travel(2)->minutes();
        Livewire::test(CustomRequestPasswordReset::class)
            ->fillForm(['email' => 'sara@office.test'])
            ->call('request')
            ->assertNotified(__('Check your email'));

        Notification::assertSentToTimes($user, ResetPasswordNotification::class, 2);
    }

    public function test_an_unknown_user_gets_the_same_answer_and_nothing_is_sent(): void
    {
        Livewire::test(CustomRequestPasswordReset::class)
            ->fillForm(['email' => 'nobody@office.test'])
            ->call('request')
            ->assertNotified(__('Check your email'));

        Notification::assertNothingSent();
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    public function test_it_says_so_when_the_system_sends_no_email(): void
    {
        config(['mail.default' => 'log']);
        User::factory()->create(['name' => 'sara', 'email' => 'sara@office.test']);

        Livewire::test(CustomRequestPasswordReset::class)
            ->fillForm(['email' => 'sara'])
            ->call('request')
            ->assertNotified(__('Emails are not set up in this system'));

        Notification::assertNothingSent();
    }

    public function test_the_link_sets_a_new_password(): void
    {
        $user = User::factory()->create(['email' => 'sara@office.test', 'password' => Hash::make('old-password')]);
        $token = Password::broker()->createToken($user);

        Livewire::test(ResetPassword::class, ['email' => $user->email, 'token' => $token])
            ->fillForm(['password' => 'A-new-password-9', 'passwordConfirmation' => 'A-new-password-9'])
            ->call('resetPassword')
            ->assertHasNoFormErrors();

        $this->assertTrue(Hash::check('A-new-password-9', $user->fresh()->password));
    }
}
