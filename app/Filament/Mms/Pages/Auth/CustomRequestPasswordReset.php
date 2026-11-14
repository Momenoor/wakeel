<?php

namespace App\Filament\Mms\Pages\Auth;

use App\Models\User;
use App\Services\MMS\SenderMailer;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Auth\Notifications\ResetPassword as ResetPasswordNotification;
use Filament\Auth\Pages\PasswordReset\RequestPasswordReset;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Support\Facades\Password;
use SensitiveParameter;
use Throwable;

/**
 * "Forgot password?" on the sign-in page: the username or the email address, as the sign-in itself
 * takes (CustomLogin), and a link to choose a new password sent to the user's email address from
 * the mailbox the system's emails go out from (Settings → Email Settings).
 *
 * The answer is the same whether or not there is such a user — a stranger learns nothing from it —
 * and says so plainly when no email can be sent at all.
 */
class CustomRequestPasswordReset extends RequestPasswordReset
{
    public function request(): void
    {
        try {
            $this->rateLimit(2);
        } catch (TooManyRequestsException $exception) {
            $this->getRateLimitedNotification($exception)?->send();

            return;
        }

        $data = $this->form->getState();

        // Nothing sends an email here (the mailer only writes to the log): said, not pretended.
        if (in_array(config('mail.default'), ['log', 'array'], true)) {
            Notification::make()
                ->title(__('Emails are not set up in this system'))
                ->body(__('Ask your system administrator to reset your password.'))
                ->warning()
                ->send();

            return;
        }

        $user = self::userFor((string) $data['email']);

        try {
            $status = $user === null ? Password::INVALID_USER : Password::broker(Filament::getAuthPasswordBroker())->sendResetLink(
                ['email' => $user->email],
                function (CanResetPassword $user, #[SensitiveParameter] string $token): void {
                    $notification = app(ResetPasswordNotification::class, ['token' => $token]);
                    $notification->url = Filament::getResetPasswordUrl($token, $user);

                    $user->notify($notification);
                },
            );
        } catch (Throwable $e) {
            report($e);

            Notification::make()
                ->title(__('The email could not be sent'))
                ->body(SenderMailer::reason($e))
                ->danger()
                ->send();

            return;
        }

        // Asked again within the minute: said, as there is a user either way to have asked twice.
        if ($status === Password::RESET_THROTTLED) {
            $this->getFailureNotification($status)?->send();

            return;
        }

        // Sent — or no such user, said the same way.
        $this->getSentNotification(Password::RESET_LINK_SENT)?->send();

        $this->form->fill();
    }

    protected function getEmailFormComponent(): Component
    {
        return TextInput::make('email')
            ->label(__('Username or Email'))
            ->required()
            ->autocomplete('username')
            ->autofocus();
    }

    protected function getSentNotification(string $status): ?Notification
    {
        return Notification::make()
            ->title(__('Check your email'))
            ->body(__('If this username or email address belongs to a user, a link to choose a new password has been sent to their email address. It works for an hour.'))
            ->success();
    }

    /** The user of that username or email address who can be sent a link: one with an address, allowed in. */
    private static function userFor(string $login): ?User
    {
        $login = trim($login);
        $user = User::query()->where(filter_var($login, FILTER_VALIDATE_EMAIL) ? 'email' : 'name', $login)->first();

        if ($user === null || blank($user->email) || ! $user->canAccessPanel(Filament::getCurrentOrDefaultPanel())) {
            return null;
        }

        return $user;
    }
}
