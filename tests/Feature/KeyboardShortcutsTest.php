<?php

namespace Tests\Feature;

use App\Filament\Mms\Pages\AdminDashboard;
use App\Filament\Mms\Resources\Matters\MatterResource;
use App\Filament\Mms\Resources\Parties\PartyResource;
use App\Filament\Shared\Pages\SystemUpdates;
use App\Filament\Shared\Pages\UserGuide;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Ctrl+K opens global search; N or Ctrl+Alt+N a page's Create — on the
 * dashboard, a new matter.
 */
class KeyboardShortcutsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        $admin = User::factory()->create();
        $admin->assignRole('super-admin');
        $this->actingAs($admin);
    }

    public function test_global_search_is_on_ctrl_k_only(): void
    {
        foreach (['mms', 'pms'] as $panel) {
            $this->assertSame(['ctrl+k'], Filament::getPanel($panel)->getGlobalSearchKeyBindings());
        }
    }

    public function test_a_lists_create_button_is_marked_for_the_shortcut(): void
    {
        $this->get(PartyResource::getUrl('index', panel: 'mms'))
            ->assertOk()
            ->assertSee('data-shortcut-create', false)
            ->assertSee("event.code !== 'KeyN'", false);
    }

    public function test_ctrl_alt_u_opens_system_updates_for_those_who_may_update(): void
    {
        $this->get(AdminDashboard::getUrl(panel: 'mms'))
            ->assertSee("event.code !== 'KeyU'", false)
            ->assertSee(str_replace('/', chr(92).'/', SystemUpdates::getUrl(panel: 'mms')), false);

        $this->actingAs(User::factory()->create());
        $this->get(UserGuide::getUrl(panel: 'mms'))->assertOk()->assertDontSee("event.code !== 'KeyU'", false);
    }

    public function test_the_dashboard_shortcut_makes_a_new_matter(): void
    {
        $this->get(AdminDashboard::getUrl(panel: 'mms'))
            ->assertOk()
            ->assertSee('href="'.e(MatterResource::getUrl('create', panel: 'mms')).'" data-shortcut-create hidden', false);
    }

    public function test_the_shortcuts_are_announced_for_two_weeks_only(): void
    {
        $this->travelTo('2026-10-19 23:00');
        $this->get(AdminDashboard::getUrl(panel: 'mms'))->assertSee(__('New keyboard shortcuts:'));
        $this->get(PartyResource::getUrl('index', panel: 'mms'))->assertSee('Ctrl+Alt+N');

        $this->travelTo('2026-10-20 00:00');
        $this->get(AdminDashboard::getUrl(panel: 'mms'))->assertDontSee(__('New keyboard shortcuts:'));
    }

    public function test_the_user_guide_is_announced_too_but_not_on_the_guide(): void
    {
        $this->travelTo('2026-10-19 23:00');
        $this->get(AdminDashboard::getUrl(panel: 'mms'))
            ->assertSee(__('Discover the User Guide'))
            ->assertSee(UserGuide::getUrl(panel: 'mms'), false);
        $this->get(UserGuide::getUrl(panel: 'mms'))->assertDontSee(__('Discover the User Guide'));

        $this->travelTo('2026-10-20 00:00');
        $this->get(AdminDashboard::getUrl(panel: 'mms'))->assertDontSee(__('Discover the User Guide'));
    }
}
