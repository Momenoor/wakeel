<?php

namespace App\Http\Controllers;

use Filament\Auth\Http\Controllers\LogoutController as FilamentLogoutController;
use Filament\Auth\Http\Responses\Contracts\LogoutResponse;
use Filament\Facades\Filament;

/**
 * Sign out of this device only. Laravel's logout() gives the user a new "remember me" token, which
 * ends the remembered sign-in on every other device too — signing out on the phone signed the office
 * PC out as soon as its session ran out (two hours idle, or the browser closed). Signing a user out
 * everywhere is the admin's Force sign out (App\Services\Auth\ForceSignOut), and a new password.
 */
class LogoutController extends FilamentLogoutController
{
    public function __invoke(): LogoutResponse
    {
        Filament::auth()->logoutCurrentDevice();

        session()->invalidate();
        session()->regenerateToken();

        return app(LogoutResponse::class);
    }
}
