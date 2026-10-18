<?php

namespace App\Filament\Shared\Pages;

use App\Filament\Shared\Clusters\Settings;
use App\Filament\Support\SettingsForm;
use App\Models\Setting;
use App\Support\Branding;
use App\Support\Integrations;
use App\Support\ScreenPermissions;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Support\Arr;

/**
 * One page of the system settings (once the tabs of a single System Settings
 * page): its own fields, filled from and saved to only its own keys, through
 * the same Setting / Branding / Integrations storage as ever — so the stored
 * keys and groups are unchanged. Access follows the permission of the tab
 * the page came from (ScreenPermissions) on top of the page-level
 * View:SystemSettings, so nobody gains or loses a setting by the split.
 */
abstract class SettingsFormPage extends Page
{
    protected static ?string $cluster = Settings::class;

    /** @var array<string, mixed> */
    public ?array $data = [];

    /**
     * The tab permission this page answers to (a ScreenPermissions constant);
     * null: none beyond View:SystemSettings (the page then checks its own rule).
     */
    abstract protected static function permission(): ?string;

    /**
     * What the form starts with: the page's own keys, as saved.
     *
     * @return array<string, mixed>
     */
    abstract protected function defaults(): array;

    /**
     * The form's components.
     *
     * @return array<int, mixed>
     */
    abstract protected function fields(): array;

    /**
     * The group each setting is stored in, where it is not "general".
     *
     * @return array<string, string>
     */
    protected function groups(): array
    {
        return [];
    }

    /**
     * Whether this user may open the page: View:SystemSettings, as the whole
     * of System Settings needed, and the permission of the tab it was.
     */
    public static function canAccess(): bool
    {
        return (auth()->user()?->can('View:SystemSettings') ?? false)
            && (static::permission() === null || ScreenPermissions::can(static::permission()));
    }

    public function getTitle(): string
    {
        return static::getNavigationLabel();
    }

    public function mount(): void
    {
        $this->form->fill($this->defaults());
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components($this->fields())
            ->statePath('data');
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                SettingsForm::make($this->getFormActions()),
            ]);
    }

    /**
     * @return array<Action>
     */
    protected function getFormActions(): array
    {
        return [
            SettingsForm::saveAction(),
        ];
    }

    public function save(): void
    {
        $state = $this->form->getState();

        $this->persist($state);

        Setting::applyMailConfig();

        $this->saved($state);

        Notification::make()
            ->title(__('Settings saved successfully'))
            ->success()
            ->send();
    }

    /**
     * Writes the page's keys: branding uploads and integrations through
     * their own savers (each skips a key the page does not have), the rest
     * as settings rows in their group.
     *
     * @param  array<string, mixed>  $state
     */
    protected function persist(array $state): void
    {
        Branding::save($state);
        Integrations::save($state);

        $groups = $this->groups();

        foreach (Arr::except($state, [...Branding::KEYS, ...Integrations::KEYS]) as $key => $value) {
            Setting::set($key, $value, $groups[$key] ?? 'general');
        }
    }

    /**
     * After the keys are written, before the "saved" notice.
     *
     * @param  array<string, mixed>  $state
     */
    protected function saved(array $state): void
    {
        //
    }
}
