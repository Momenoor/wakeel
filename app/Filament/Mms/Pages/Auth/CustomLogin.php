<?php

namespace App\Filament\Mms\Pages\Auth;

use Filament\Auth\Pages\Login;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

class CustomLogin extends Login
{
    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->getLoginFormComponent(),
                $this->getPasswordFormComponent(),
                $this->getRememberFormComponent(),
            ]);
    }

    protected function getLoginFormComponent(): Component
    {
        return TextInput::make('login')
            ->label(__('Username or Email'))
            ->required()
            ->autocomplete()
            ->autofocus();
    }

    protected function getCredentialsFromFormData(#[SensitiveParameter] array $data): array
    {
        // Detect whether input is email or username
        $loginField = filter_var($data['login'], FILTER_VALIDATE_EMAIL)
            ? 'email'
            : 'name';

        return [
            $loginField => $data['login'],
            'password' => $data['password'],
        ];
    }

    /**
     * Filament reports a failed login on `data.email`; this form's field
     * is `login`, so the message landed on a field that isn't there, and
     * wrong credentials showed nothing at all.
     */
    protected function throwFailureValidationException(): never
    {
        throw ValidationException::withMessages([
            'data.login' => __('filament-panels::auth/pages/login.messages.failed'),
        ]);
    }
}
