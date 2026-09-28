<?php

namespace Tests\Feature;

use App\Filament\Mms\Pages\Auth\CustomLogin;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Wrong credentials must show an error on the form's own field — Filament
 * reports it on `email`, which this form renames to `login`.
 */
class LoginFailureMessageTest extends TestCase
{
    use RefreshDatabase;

    public function test_wrong_credentials_show_an_error_on_the_login_field(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('pms'));
        User::factory()->create(['email' => 'user@example.com', 'password' => bcrypt('right-password')]);

        Livewire::test(CustomLogin::class)
            ->fillForm(['login' => 'user@example.com', 'password' => 'wrong-password'])
            ->call('authenticate')
            ->assertHasFormErrors(['login'])
            ->assertSee(__('filament-panels::auth/pages/login.messages.failed'));

        $this->assertGuest();
    }
}
