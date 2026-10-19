<?php

namespace App\Filament\Mms\Pages\Auth;

use App\Filament\Support\NotificationPreferenceFields;
use App\Models\Theme;
use App\Services\Notifications\NotificationPreferences;
use App\Support\ThemeManager;
use Filament\Auth\Pages\EditProfile;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;

class CustomProfile extends EditProfile
{
    /** @var array<string, array<string, bool>> */
    protected array $notificationChoices = [];

    /** The whole width of the page: two columns of sections side by side. */
    public function getMaxContentWidth(): Width|string|null
    {
        return Width::Full;
    }

    public function form(Schema $schema): Schema
    {
        // The notification areas shared between the two columns by their size — the account has about
        // eight fields above its column, the password three.
        [$left, $right] = NotificationPreferenceFields::forUserInColumns($this->getUser(), leftStart: 8, rightStart: 3);

        return $schema->components([
            Grid::make(2)
                ->columnSpanFull()
                ->schema([
                    // Each column its own stack of sections, so a short one never leaves a gap beside a tall one.
                    Group::make([
                        // Who they are: the picture, the name, display name and email.
                        Section::make(__('Account'))
                            ->icon('heroicon-o-user')
                            ->columns(2)
                            ->schema([
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
                                TextInput::make('display_name')
                                    ->label(__('Display Name'))
                                    ->required(),
                                TextInput::make('email')
                                    ->label(__('Email'))
                                    ->required()
                                    ->email()
                                    ->columnSpanFull(),
                                // Their own look, when the office lets each choose (Settings → Themes).
                                Select::make('theme_id')
                                    ->label(__('Theme'))
                                    ->options(fn (): array => Theme::query()->orderBy('name')->pluck('name', 'id')->all())
                                    ->placeholder(__('The office theme'))
                                    ->visible(fn (): bool => app(ThemeManager::class)->allowsPersonal() && Theme::query()->exists())
                                    ->columnSpanFull(),
                            ]),
                        // What they are told of, by which channel — within what their roles offer.
                        Text::make(__('Choose what you are told of, and how: the bell (with its pop-up and your phone), email or WhatsApp. What your role requires is on and cannot be changed.'))
                            ->color('gray'),
                        ...$left,
                    ])->columnSpan(1),
                    Group::make([
                        // Changing it: the new one twice, and the current one to confirm.
                        Section::make(__('Password'))
                            ->description(__('Leave empty to keep the current password.'))
                            ->icon('heroicon-o-key')
                            ->schema([
                                $this->getPasswordFormComponent()->inlineLabel(false),
                                $this->getPasswordConfirmationFormComponent()->inlineLabel(false),
                                $this->getCurrentPasswordFormComponent()->inlineLabel(false),
                            ]),
                        ...$right,
                    ])->columnSpan(1),
                ]),
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

        // Another theme: the page is drawn again in it.
        if ($this->getUser()->wasChanged('theme_id')) {
            $this->js('window.location.reload()');
        }
    }
}
