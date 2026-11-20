<?php

namespace Tests\Feature;

use App\Filament\Mms\Resources\Matters\MatterResource;
use App\Models\Matter;
use App\Models\User;
use App\Support\Favorites;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Favourites: a page starred from the side menu or from the page itself, each system its own list, shown as a
 * "Favorites" group at the top of the side menu.
 */
class FavoritesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('en');
        Filament::setCurrentPanel(Filament::getPanel('mms'));
        $this->user = User::factory()->create();
        $this->user->assignRole(Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']));
        $this->actingAs($this->user);
    }

    public function test_a_menu_page_and_a_record_are_starred_and_shown_at_the_top_of_the_side_menu(): void
    {
        $matter = Matter::factory()->create();
        $list = MatterResource::getUrl('index');
        $one = MatterResource::getUrl('view', ['record' => $matter]);

        // Starred from the menu (its full address), and from the matter's own page.
        $this->postJson(route('favorites.toggle'), ['panel' => 'mms', 'url' => $list, 'label' => 'whatever'])
            ->assertOk()->assertJson(['favorite' => true]);
        $response = $this->postJson(route('favorites.toggle'), ['panel' => 'mms', 'url' => parse_url($one, PHP_URL_PATH).'#x', 'label' => '<b>Matter 7/2026</b>'])
            ->assertOk()->assertJson(['favorite' => true]);

        $this->assertSame([parse_url($list, PHP_URL_PATH), parse_url($one, PHP_URL_PATH)], $response->json('urls'));
        $this->assertSame('Matter 7/2026', Favorites::of($this->user->fresh(), 'mms')[1]['label']);
        // The other system's list is its own.
        $this->assertSame([], Favorites::of($this->user->fresh(), 'pms'));

        // The menu page by its menu name now (not what was sent); the matter by its heading, with the Matters icon.
        $items = Favorites::items($this->user->fresh(), Filament::getPanel('mms'), parse_url($one, PHP_URL_PATH));
        $this->assertSame([MatterResource::getNavigationLabel(), 'Matter 7/2026'], array_map(fn ($item) => $item->getLabel(), $items));
        $this->assertEquals($items[0]->getIcon(), $items[1]->getIcon());
        $this->assertSame([false, true], array_map(fn ($item) => $item->isActive(), $items));

        // On every page: the group, the page's star, the script.
        $this->get($list)->assertOk()
            ->assertSee('wk-favorites', false)
            ->assertSee('Matter 7/2026')
            ->assertSee('data-wk-fav-page', false)
            ->assertSee('favorites\/toggle', false);

        // Starred again: taken out.
        $this->postJson(route('favorites.toggle'), ['panel' => 'mms', 'url' => $list])->assertOk()->assertJson(['favorite' => false]);
        $this->assertSame([parse_url($one, PHP_URL_PATH)], Favorites::urls($this->user->fresh(), 'mms'));
    }

    public function test_only_this_sites_pages_and_a_known_system(): void
    {
        $this->postJson(route('favorites.toggle'), ['panel' => 'mms', 'url' => 'https://evil.example/x'])->assertStatus(422);
        $this->postJson(route('favorites.toggle'), ['panel' => 'mms', 'url' => '//evil.example/x'])->assertStatus(422);
        $this->postJson(route('favorites.toggle'), ['panel' => 'nope', 'url' => '/x'])->assertNotFound();
        $this->assertSame([], Favorites::of($this->user->fresh(), 'mms'));
    }

    public function test_the_list_holds_up_to_its_most(): void
    {
        foreach (range(1, Favorites::MAX) as $i) {
            $this->assertTrue(Favorites::toggle($this->user, 'mms', '/page-'.$i, 'Page '.$i));
        }

        $this->postJson(route('favorites.toggle'), ['panel' => 'mms', 'url' => '/one-more'])->assertStatus(422);
        $this->assertCount(Favorites::MAX, Favorites::of($this->user->fresh(), 'mms'));
    }
}
