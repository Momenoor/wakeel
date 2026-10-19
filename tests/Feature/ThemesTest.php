<?php

namespace Tests\Feature;

use App\Enums\ThemeDensity;
use App\Enums\ThemeRadius;
use App\Enums\ThemeSidebar;
use App\Filament\Mms\Pages\Auth\CustomProfile;
use App\Filament\Shared\Resources\Themes\Pages\ManageThemes;
use App\Filament\Shared\Resources\Themes\ThemeResource;
use App\Models\Setting;
use App\Models\Theme;
use App\Models\User;
use App\Support\ThemeManager;
use Filament\Actions\Testing\TestAction;
use Filament\Enums\ThemeMode;
use Filament\Facades\Filament;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Width;
use Filament\Support\Facades\FilamentColor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Themes: the office's look and each user's own — colours given to Filament, corners, spacing and sidebar as
 * CSS variables — chosen in Settings → Themes and in the profile, with nothing compiled.
 */
class ThemesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('mms');
    }

    private function theme(array $attributes = []): Theme
    {
        return Theme::create([
            'name' => 'Emerald',
            'colors' => ['primary' => '#047857'],
            'radius' => ThemeRadius::NONE,
            'density' => ThemeDensity::COMPACT,
            'sidebar' => ThemeSidebar::PLAIN,
            ...$attributes,
        ]);
    }

    public function test_with_no_theme_the_system_looks_as_it_was_built(): void
    {
        $this->actingAs(User::factory()->create());

        $this->assertNull(app(ThemeManager::class)->current());
        $this->assertSame('', app(ThemeManager::class)->css());
        $this->assertSame([], app(ThemeManager::class)->colors());
    }

    public function test_the_office_theme_gives_every_page_its_colours_corners_and_spacing(): void
    {
        Gate::before(fn () => true);
        $theme = $this->theme(['sidebar' => ThemeSidebar::COLORED]);
        Setting::set(ThemeManager::SETTING, $theme->id, 'appearance');

        $this->actingAs(User::factory()->create())
            ->get(ThemeResource::getUrl())
            ->assertOk()
            // Square corners and the compact spacing, as CSS variables in the page.
            ->assertSee('--radius-lg: 0rem;', escape: false)
            ->assertSee('--spacing: 0.2rem;', escape: false)
            ->assertSee('.fi-sidebar, .fi-sidebar.fi-sidebar-open { background-color: var(--primary-800); }', escape: false);

        // Filament made the shades of the primary colour from the one chosen.
        $this->assertSame(Color::generatePalette('#047857')[500], FilamentColor::getColor('primary')[500]);
    }

    public function test_a_user_may_choose_their_own_unless_the_office_keeps_everyone_to_its_own(): void
    {
        $office = $this->theme(['name' => 'Office']);
        $mine = $this->theme(['name' => 'Mine', 'colors' => ['primary' => '#9f1239']]);
        Setting::set(ThemeManager::SETTING, $office->id, 'appearance');
        $user = User::factory()->create(['theme_id' => $mine->id]);

        $this->assertTrue($mine->is(app(ThemeManager::class)->current($user)));

        Setting::set(ThemeManager::LOCKED, true, 'appearance');
        $this->assertTrue($office->is(app(ThemeManager::class)->current($user)));

        // Their theme deleted: the office's again.
        Setting::set(ThemeManager::LOCKED, false, 'appearance');
        $mine->delete();
        $this->assertTrue($office->is(app(ThemeManager::class)->current($user->refresh())));
    }

    public function test_themes_are_made_from_filaments_palettes_or_own_colours_and_one_is_made_the_offices(): void
    {
        Gate::before(fn () => true);
        $this->actingAs(User::factory()->create());

        Livewire::test(ManageThemes::class)
            ->callAction('starters')
            ->assertNotified()
            ->callAction('create', [
                'name' => 'Our green',
                // A palette of Filament's, and a colour of our own.
                'colors' => ['primary' => 'emerald', 'secondary' => 'custom', 'gray' => 'stone'],
                'custom_colors' => ['secondary' => '#b45309'],
                'radius' => ThemeRadius::LARGE->value,
                'density' => ThemeDensity::COMFORTABLE->value,
                'sidebar' => ThemeSidebar::COLORED->value,
                'options' => [
                    'navigation' => 'top',
                    'mode_switch' => 'dark',
                    'surfaces' => ['topbar' => ['light' => '#0f172a', 'dark' => '#020617']],
                ],
            ])
            ->assertHasNoActionErrors();

        // The original looks and one per Filament palette, and ours.
        $this->assertSame(count(ManageThemes::starters()) + 1, Theme::count());
        $this->assertSame(count(Color::all()) + 2, count(ManageThemes::starters()));
        $theme = Theme::where('name', 'Our green')->sole();
        $this->assertSame(['emerald', '#b45309', 'stone'], [$theme->colors['primary'], $theme->colors['secondary'], $theme->colors['gray']]);
        $this->assertSame(['top', 'dark', '#0f172a'], [$theme->option('navigation'), $theme->option('mode_switch'), $theme->surface('topbar', 'light')]);
        // Filament's palette as it is; our own colour for Filament to make the shades of.
        $this->assertSame([Color::Emerald, '#b45309'], [$theme->setColors()['primary'], $theme->setColors()['secondary']]);

        // Your own colour, but not a colour: refused.
        Livewire::test(ManageThemes::class)
            ->callAction('create', ['name' => 'Bad', 'colors' => ['primary' => 'custom'], 'custom_colors' => ['primary' => 'red'], 'radius' => 'md', 'density' => 'normal', 'sidebar' => 'plain'])
            ->assertHasActionErrors(['custom_colors.primary']);

        // Edited: the hex comes back as "your own".
        Livewire::test(ManageThemes::class)
            ->mountAction(TestAction::make('edit')->table($theme))
            ->assertActionDataSet(['colors.secondary' => 'custom', 'custom_colors.secondary' => '#b45309']);

        Livewire::test(ManageThemes::class)->callAction(TestAction::make('useForOffice')->table($theme));
        $this->assertSame((string) $theme->id, (string) Setting::get(ThemeManager::SETTING));

        // Starters added twice: still once each.
        Livewire::test(ManageThemes::class)->callAction('starters');
        $this->assertSame(count(ManageThemes::starters()) + 1, Theme::count());
    }

    public function test_the_layout_light_and_dark_and_the_surfaces_reach_the_page(): void
    {
        Gate::before(fn () => true);
        Storage::fake('public');
        Storage::disk('public')->put('themes/logo.png', 'png');
        $theme = $this->theme(['options' => [
            'navigation' => 'top',
            'sidebar_width' => '20rem',
            'content_width' => 'full',
            'mode' => 'dark',
            'mode_switch' => 'dark',
            'topbar_style' => 'primary',
            'cards' => 'flat',
            'headings' => 'bold',
            'logo' => 'themes/logo.png',
            'logo_height' => '5rem',
            'surfaces' => [
                'background' => ['light' => '#f5f5f4', 'dark' => '#0c0a09'],
                'sidebar' => ['light' => '#fde68a'],
            ],
        ]]);
        Setting::set(ThemeManager::SETTING, $theme->id, 'appearance');

        $this->actingAs(User::factory()->create())
            ->get(ThemeResource::getUrl())
            ->assertOk()
            ->assertSee('.fi-body { background-color: #f5f5f4; }', escape: false)
            ->assertSee('html.dark .fi-body { background-color: #0c0a09; }', escape: false)
            // A light sidebar: its words dark, to be read.
            ->assertSee('.fi-sidebar, .fi-sidebar.fi-sidebar-open { background-color: #fde68a; }', escape: false)
            ->assertSee('.fi-sidebar .fi-sidebar-item-label', escape: false)
            ->assertSee('color: #111827;', escape: false)
            ->assertSee('.fi-topbar { background-color: var(--primary-600); }', escape: false)
            ->assertSee('--font-weight-semibold: 700;', escape: false)
            ->assertSee('box-shadow: 0 0 0 1px var(--gray-200) !important;', escape: false)
            ->assertSee(Storage::disk('public')->url('themes/logo.png'), escape: false);

        $panel = Filament::getCurrentPanel();
        $this->assertTrue($panel->hasTopNavigation());
        $this->assertSame('20rem', $panel->getSidebarWidth());
        $this->assertSame(Width::Full, $panel->getMaxContentWidth());
        $this->assertSame(ThemeMode::Dark, $panel->getDefaultThemeMode());
        $this->assertTrue($panel->hasDarkModeForced());
        $this->assertSame('5rem', $panel->getBrandLogoHeight());
    }

    public function test_an_option_that_is_not_one_of_its_choices_never_reaches_the_page(): void
    {
        $theme = $this->theme(['options' => ['sidebar_width' => '99rem; } body { display:none', 'navigation' => 'top']]);

        $this->assertSame('17rem', $theme->option('sidebar_width'));
        $this->assertSame('top', $theme->option('navigation'));
        $this->assertNull($theme->surface('cards', 'light'));
    }

    public function test_the_screen_is_for_whoever_looks_after_the_general_settings(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(ThemeResource::getUrl())->assertForbidden();
    }

    public function test_the_theme_is_chosen_in_the_profile(): void
    {
        $theme = $this->theme();
        $user = User::factory()->create(['display_name' => 'Momen']);
        $this->actingAs($user);

        Livewire::test(CustomProfile::class)
            ->assertFormFieldVisible('theme_id')
            ->fillForm(['theme_id' => $theme->id])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($theme->id, $user->refresh()->theme_id);

        // Kept to the office's: not offered.
        Setting::set(ThemeManager::LOCKED, true, 'appearance');
        Livewire::test(CustomProfile::class)->assertFormFieldHidden('theme_id');
    }
}
