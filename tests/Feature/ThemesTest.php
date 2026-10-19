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
use Filament\Facades\Filament;
use Filament\Support\Colors\Color;
use Filament\Support\Facades\FilamentColor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
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

    public function test_themes_are_made_and_one_is_made_the_offices(): void
    {
        Gate::before(fn () => true);
        $this->actingAs(User::factory()->create());

        Livewire::test(ManageThemes::class)
            ->callAction('starters')
            ->assertNotified()
            ->callAction('create', [
                'name' => 'Our green',
                'colors' => ['primary' => '#047857', 'gray' => '#6b7280'],
                'radius' => ThemeRadius::LARGE->value,
                'density' => ThemeDensity::COMFORTABLE->value,
                'sidebar' => ThemeSidebar::COLORED->value,
            ])
            ->assertHasNoActionErrors();

        $this->assertSame(count(ManageThemes::STARTERS) + 1, Theme::count());
        $theme = Theme::where('name', 'Our green')->sole();
        $this->assertSame([ThemeRadius::LARGE, '#047857'], [$theme->radius, $theme->colors['primary']]);

        // Not a colour: refused.
        Livewire::test(ManageThemes::class)
            ->callAction('create', ['name' => 'Bad', 'colors' => ['primary' => 'red'], 'radius' => 'md', 'density' => 'normal', 'sidebar' => 'plain'])
            ->assertHasActionErrors(['colors.primary']);

        Livewire::test(ManageThemes::class)->callAction(TestAction::make('useForOffice')->table($theme));
        $this->assertSame((string) $theme->id, (string) Setting::get(ThemeManager::SETTING));

        // Starters added twice: still once each.
        Livewire::test(ManageThemes::class)->callAction('starters');
        $this->assertSame(count(ManageThemes::STARTERS) + 1, Theme::count());
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
