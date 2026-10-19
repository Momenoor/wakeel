<?php

namespace Tests\Feature;

use App\Filament\Shared\Pages\SystemUpdates;
use App\Models\Setting;
use App\Models\Theme;
use App\Models\User;
use App\Support\ThemeManager;
use Filament\Facades\Filament;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * Switching between the systems: at the top of the sidebar (in the top bar it ran a phone's bar past the
 * screen), and in the top bar only when a theme puts the navigation there and there is no sidebar.
 */
class SystemSwitcherTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Gate::before(fn () => true);
        Filament::setCurrentPanel('mms');
        $this->actingAs(User::factory()->create());
    }

    public function test_it_is_at_the_top_of_the_sidebar_not_in_the_top_bar(): void
    {
        $this->get(SystemUpdates::getUrl())->assertOk();

        $sidebar = (string) FilamentView::renderHook(PanelsRenderHook::SIDEBAR_NAV_START);
        $topbar = (string) FilamentView::renderHook(PanelsRenderHook::GLOBAL_SEARCH_BEFORE);

        $this->assertStringContainsString('wk-system-switcher', $sidebar);
        $this->assertStringContainsString('x-show="$store.sidebar.isOpen"', $sidebar);
        $this->assertStringNotContainsString('wk-system-switcher', $topbar);
    }

    public function test_with_the_navigation_on_top_it_is_in_the_top_bar(): void
    {
        $theme = Theme::create(['name' => 'Top', 'options' => ['navigation' => 'top']]);
        Setting::set(ThemeManager::SETTING, $theme->id, 'appearance');

        $this->get(SystemUpdates::getUrl())->assertOk();

        $this->assertStringContainsString('wk-system-switcher', (string) FilamentView::renderHook(PanelsRenderHook::GLOBAL_SEARCH_BEFORE));
        $this->assertStringNotContainsString('wk-system-switcher', (string) FilamentView::renderHook(PanelsRenderHook::SIDEBAR_NAV_START));
    }
}
