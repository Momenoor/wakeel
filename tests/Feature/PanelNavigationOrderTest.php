<?php

namespace Tests\Feature;

use App\Models\User;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PanelNavigationOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_mms_panel_navigation_groups_order_en(): void
    {
        app()->setLocale('en');
        $panel = Filament::getPanel('mms');
        Filament::setCurrentPanel($panel);

        $groups = collect($panel->getNavigationGroups())
            ->map(fn ($g) => $g instanceof NavigationGroup ? $g->getLabel() : $g)
            ->values()
            ->all();

        $this->assertSame([
            'Communication',
            'Financial',
            'Human Resources',
            'Administration',
        ], $groups);
    }

    public function test_mms_panel_navigation_groups_order_ar(): void
    {
        app()->setLocale('ar');
        $panel = Filament::getPanel('mms');
        Filament::setCurrentPanel($panel);

        $groups = collect($panel->getNavigationGroups())
            ->map(fn ($g) => $g instanceof NavigationGroup ? $g->getLabel() : $g)
            ->values()
            ->all();

        $this->assertSame([
            __('Communication'),
            __('Financial'),
            __('Human Resources'),
            __('filament-shield::filament-shield.nav.group'),
        ], $groups);
    }

    public function test_pms_panel_navigation_groups_order_en(): void
    {
        app()->setLocale('en');
        $panel = Filament::getPanel('pms');
        Filament::setCurrentPanel($panel);

        $groups = collect($panel->getNavigationGroups())
            ->map(fn ($g) => $g instanceof NavigationGroup ? $g->getLabel() : $g)
            ->values()
            ->all();

        $this->assertSame([
            'Properties',
            'Leasing',
            'Ownership',
            'Administration',
        ], $groups);
    }

    public function test_pms_panel_navigation_groups_order_ar(): void
    {
        app()->setLocale('ar');
        $panel = Filament::getPanel('pms');
        Filament::setCurrentPanel($panel);

        $groups = collect($panel->getNavigationGroups())
            ->map(fn ($g) => $g instanceof NavigationGroup ? $g->getLabel() : $g)
            ->values()
            ->all();

        $this->assertSame([
            __('Properties'),
            __('Leasing'),
            __('Ownership'),
            __('filament-shield::filament-shield.nav.group'),
        ], $groups);
    }

    public function test_mms_panel_rendered_navigation_order(): void
    {
        $role = Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->assignRole($role);

        $this->actingAs($user);
        app()->setLocale('en');

        $panel = Filament::getPanel('mms');
        Filament::setCurrentPanel($panel);

        $navGroups = collect(Filament::getNavigation())
            ->map(fn (NavigationGroup $group) => $group->getLabel())
            ->filter()
            ->values()
            ->all();

        $this->assertSame('Administration', end($navGroups), 'Administration should be the last group');
        $this->assertNotContains('Settings', $navGroups, 'Settings is one entry inside Administration, not a group');

        $administration = collect(Filament::getNavigation())
            ->first(fn (NavigationGroup $group) => $group->getLabel() === 'Administration')
            ->getItems();

        $this->assertSame('Settings', collect($administration)->first()?->getLabel(), 'Settings should lead the Administration group');
    }

    public function test_pms_panel_rendered_navigation_order(): void
    {
        $role = Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->assignRole($role);

        $this->actingAs($user);
        app()->setLocale('en');

        $panel = Filament::getPanel('pms');
        Filament::setCurrentPanel($panel);

        $navGroups = collect(Filament::getNavigation())
            ->map(fn (NavigationGroup $group) => $group->getLabel())
            ->filter()
            ->values()
            ->all();

        $this->assertSame('Administration', end($navGroups), 'Administration should be the last group');
        $this->assertNotContains('Settings', $navGroups, 'Settings is one entry inside Administration, not a group');

        $administration = collect(Filament::getNavigation())
            ->first(fn (NavigationGroup $group) => $group->getLabel() === 'Administration')
            ->getItems();

        $this->assertSame('Settings', collect($administration)->first()?->getLabel(), 'Settings should lead the Administration group');
    }
}
