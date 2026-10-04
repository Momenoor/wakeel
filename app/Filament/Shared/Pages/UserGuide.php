<?php

namespace App\Filament\Shared\Pages;

use App\Support\Guide\Guide;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;

/**
 * "How to use" — every screen and action of the system explained step by
 * step with screenshots, who can do it, and tips. Open to every signed-in
 * user: the guide itself shows which roles an action needs.
 */
class UserGuide extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::AcademicCap;

    // First in the menu, above every group, styled apart (theme.css:
    // .fi-sidebar-item a[href$="/user-guide"]).
    protected static ?int $navigationSort = -100;

    protected static ?string $slug = 'user-guide';

    protected string $view = 'filament.shared.user-guide';

    #[Url(as: 'module')]
    public string $module = '';

    public static function getNavigationLabel(): string
    {
        return __('User Guide');
    }

    public static function getNavigationGroup(): ?string
    {
        return null;
    }

    public function getTitle(): string
    {
        return __('User Guide');
    }

    public function mount(): void
    {
        $modules = $this->modules();

        if (! collect($modules)->contains('id', $this->module)) {
            $this->module = $modules[0]['id'] ?? '';
        }
    }

    /**
     * @return list<array<string, string>>
     */
    public function modules(): array
    {
        return Guide::modules(Filament::getCurrentPanel()?->getId());
    }

    /**
     * @return array<string, mixed>|null
     */
    public function current(): ?array
    {
        return Guide::module($this->module);
    }
}
