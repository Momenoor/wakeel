<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Shared\Pages\UserGuide;
use App\Models\User;
use App\Support\Guide\Guide;
use Database\Seeders\ProductionDatabaseSeeder;
use Filament\Facades\Filament;
use Illuminate\Auth\Events\Login;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The User Guide is content people rely on, so its files are checked like
 * code: every module has the shape the page draws, every screenshot a step
 * points to exists, and every permission it names is a real one.
 */
class UserGuideTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return list<array{0: string, 1: string}> locale, module
     */
    public static function modules(): array
    {
        $rows = [];

        foreach (glob(__DIR__.'/../../resources/guide/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $locale = basename($dir);

            if (! is_file($dir.'/index.php')) {
                continue; // a language whose guide is not written yet
            }

            foreach ((require $dir.'/index.php')['modules'] as $module) {
                $rows["{$locale}/{$module['id']}"] = [$locale, $module['id']];
            }
        }

        return $rows;
    }

    #[DataProvider('modules')]
    public function test_every_listed_module_has_well_formed_content(string $locale, string $id): void
    {
        $file = resource_path("guide/{$locale}/{$id}.php");
        $this->assertFileExists($file);

        $module = require $file;
        $this->assertNotEmpty($module['title']);
        $this->assertNotEmpty($module['intro']);
        $this->assertNotEmpty($module['actions']);

        $ids = [];
        foreach ($module['actions'] as $action) {
            foreach (['id', 'title', 'description', 'roles', 'steps'] as $key) {
                $this->assertArrayHasKey($key, $action, "{$id}: action is missing '{$key}'");
            }
            $this->assertNotEmpty($action['roles'], "{$id}/{$action['id']} says nobody can do it");
            $this->assertNotEmpty($action['steps'], "{$id}/{$action['id']} has no steps");
            $this->assertNotContains($action['id'], $ids, "{$id}: duplicate action id {$action['id']}");
            $ids[] = $action['id'];

            foreach ($action['steps'] as $step) {
                $this->assertNotEmpty($step['text'], "{$id}/{$action['id']} has a step without text");
            }
        }
    }

    #[DataProvider('modules')]
    public function test_every_screenshot_a_step_uses_exists(string $locale, string $id): void
    {
        foreach ((require resource_path("guide/{$locale}/{$id}.php"))['actions'] as $action) {
            foreach ($action['steps'] as $step) {
                if (isset($step['shot'])) {
                    $path = str_contains($step['shot'], '/') ? $step['shot'] : "{$id}/{$step['shot']}";

                    $this->assertFileExists(
                        public_path("guide/{$locale}/{$path}.webp"),
                        "{$id}/{$action['id']} points to a screenshot that is missing",
                    );
                }
            }
        }
    }

    public function test_the_permissions_the_guide_names_are_real(): void
    {
        $this->seed(ProductionDatabaseSeeder::class);

        foreach (self::modules() as [$locale, $id]) {
            foreach ((require resource_path("guide/{$locale}/{$id}.php"))['actions'] as $action) {
                foreach ($action['permissions'] ?? [] as $permission) {
                    $name = trim(explode(' ', $permission)[0]);

                    $this->assertTrue(
                        Permission::where('name', $name)->exists(),
                        "{$id}/{$action['id']} names the permission {$name}, which does not exist",
                    );
                }
            }
        }
    }

    public function test_the_guide_page_renders_for_a_signed_in_user(): void
    {
        $this->actingAs(User::factory()->create());
        Filament::setCurrentPanel('mms');

        $this->get(UserGuide::getUrl(['module' => 'matters']))
            ->assertSuccessful()
            ->assertSee(Guide::module('matters')['title'])
            // On a phone, a dropdown of the sections instead of the list
            // that filled the screen above the guide.
            ->assertSee('id="wk-guide-picker"', false)
            ->assertSee('<option value="matters" selected', false);
    }

    public function test_the_first_page_after_signing_in_introduces_the_guide(): void
    {
        Gate::before(fn () => true);
        $user = User::factory()->create();
        Filament::setCurrentPanel('mms');
        $this->actingAs($user);
        $page = route('filament.mms.pages.chat');

        // Signing in asks for it: the first page shows it, the next does not.
        event(new Login('web', $user, false));
        $this->get($page)
            ->assertSuccessful()
            ->assertSee('wakeel-user-guide-intro', false)
            // Remembered per user, in a cookie, once opened or skipped.
            ->assertSee('wakeel_guide_intro_'.$user->id, false)
            ->assertSee(__('Discover the User Guide'))
            // "Open the guide" is a link to it: a function of the dialog's
            // own scope (Filament's open()) had answered the click instead.
            ->assertSee('href="'.UserGuide::getUrl().'"', false)
            ->assertDontSee('x-on:click="open()"', false);
        $this->get($page)->assertDontSee('wakeel-user-guide-intro', false);

        // Not on the guide itself.
        event(new Login('web', $user, false));
        $this->get(UserGuide::getUrl(['module' => 'matters']))->assertDontSee('wakeel-user-guide-intro', false);
    }

    public function test_a_language_without_a_guide_falls_back_to_arabic(): void
    {
        app()->setLocale('de');

        $this->assertSame('ar', Guide::locale());
    }
}
