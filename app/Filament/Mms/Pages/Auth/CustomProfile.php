<?php

namespace App\Filament\Mms\Pages\Auth;

use App\Filament\Support\NotificationPreferenceFields;
use App\Services\Notifications\NotificationPreferences;
use Filament\Auth\Pages\EditProfile;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CustomProfile extends EditProfile
{
    /** @var array<string, array<string, bool>> */
    protected array $notificationChoices = [];

    public function hasLogo(): bool
    {
        return true;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            FileUpload::make('profile_photo_path')
                ->label(__('Avatar'))
                ->avatar()
                ->image()
                ->imageEditor()
                ->columnSpanFull()
                ->alignCenter(),
            TextInput::make('name')
                ->label(__('Name'))
                ->required(),
            TextInput::make('email')
                ->label(__('Email'))
                ->required()
                ->email(),
            TextInput::make('display_name')
                ->label(__('Display Name'))
                ->required(),
            $this->getPasswordFormComponent(),
            $this->getPasswordConfirmationFormComponent(),
            $this->getCurrentPasswordFormComponent(),
            // What they are told of, by which channel — within what their roles offer.
            Section::make(__('My notifications'))
                ->description(__('Choose what you are told of, and how: the bell (with its pop-up and your phone), email or WhatsApp. What your role requires is on and cannot be changed.'))
                ->icon('heroicon-o-bell')
                ->columnSpanFull()
                ->schema(fn (): array => NotificationPreferenceFields::forUser($this->getUser())),
        ]);
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data[NotificationPreferenceFields::STATE] = NotificationPreferenceFields::userState($this->getUser());

        return $data;
    }

    /** The choices are not the user's own columns: kept apart, saved after. */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->notificationChoices = NotificationPreferenceFields::choices((array) ($data[NotificationPreferenceFields::STATE] ?? []));
        unset($data[NotificationPreferenceFields::STATE]);

        return $data;
    }

    protected function afterSave(): void
    {
        NotificationPreferences::make()->saveUser($this->getUser(), $this->notificationChoices);
    }
}
