<?php

namespace App\Filament\Shared\Pages;

use App\Filament\Shared\Clusters\Settings;
use App\Filament\Support\NotificationPreferenceFields;
use App\Filament\Support\SettingsForm;
use App\Services\Notifications\NotificationPreferences;
use App\Support\ScreenPermissions;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Spatie\Permission\Models\Role;
use UnitEnum;

/**
 * What each role is told of, and how: for every type of notification and each channel it can use —
 * on or off to begin with (the user can change it on their profile), always on, or not offered at all.
 * Nothing set here is the types' own defaults (what the system sends today).
 */
class NotificationSettings extends Page
{
    protected static ?string $cluster = Settings::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBell;

    protected static string|UnitEnum|null $navigationGroup = 'Communication';

    protected static ?int $navigationSort = 30;

    /** @var array<string, mixed> */
    public ?array $data = [];

    public static function getNavigationGroup(): ?string
    {
        return __('Communication');
    }

    public static function getNavigationLabel(): string
    {
        return __('Notification preferences');
    }

    public function getTitle(): string
    {
        return __('Who is told of what');
    }

    public function getSubheading(): ?string
    {
        return __('Choose a role, then for each notification how it reaches them: on or off to begin with, always on, or not offered. Everyone can change what is not mandatory on their own profile.');
    }

    public function mount(): void
    {
        $this->form->fill(['role_id' => Role::query()->orderBy('name')->value('id')]);
        $this->loadRole();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Select::make('role_id')
                    ->label(__('Role'))
                    ->options(fn (): array => Role::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->live()
                    ->afterStateUpdated(fn () => $this->loadRole())
                    ->required(),
                Group::make(fn (Get $get): array => filled($get('role_id')) ? NotificationPreferenceFields::forRole() : [])
                    ->key('grid'),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([SettingsForm::make()]);
    }

    public static function canAccess(): bool
    {
        return (auth()->user()?->can('View:SystemSettings') ?? false) && ScreenPermissions::can(ScreenPermissions::SETTINGS_NOTIFICATIONS_TAB);
    }

    /** The chosen role's states into the form. */
    public function loadRole(): void
    {
        $role = Role::find($this->data['role_id'] ?? null);

        $this->form->fill([
            'role_id' => $role?->getKey(),
            NotificationPreferenceFields::STATE => $role ? NotificationPreferenceFields::roleState($role) : [],
        ]);
    }

    public function save(): void
    {
        $state = $this->form->getState();
        $role = Role::findOrFail($state['role_id']);

        NotificationPreferences::make()->saveRole($role, NotificationPreferenceFields::roleStates((array) ($state[NotificationPreferenceFields::STATE] ?? [])));

        Notification::make()->success()->title(__('Saved'))->send();
    }
}
