<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\Updater\NativeMaintenance;
use App\Services\Updater\Updater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Laravel's native maintenance mode for the update (see NativeMaintenance):
 * the page that is served before the framework loads, the way back in for
 * whoever runs the update, and the site coming back up. Run against a
 * throwaway storage folder — the real one must never be put into maintenance.
 */
class NativeMaintenanceTest extends TestCase
{
    use RefreshDatabase;

    private string $storage;

    private string $originalStorage;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalStorage = $this->app->storagePath();
        $this->storage = str_replace('\\', '/', sys_get_temp_dir()).'/native-maintenance-test-'.uniqid();
        File::ensureDirectoryExists($this->storage.'/framework');
        $this->app->useStoragePath($this->storage);

        NativeMaintenance::$inTests = true;
    }

    protected function tearDown(): void
    {
        NativeMaintenance::$inTests = false;
        $this->app->useStoragePath($this->originalStorage);
        Setting::clearCache();
        File::deleteDirectory($this->storage);

        parent::tearDown();
    }

    public function test_down_puts_up_a_page_served_before_the_framework_with_a_way_in(): void
    {
        $native = app(NativeMaintenance::class);

        $native->down();

        $this->assertTrue($native->isDown());
        $this->assertFileExists($this->storage.'/framework/maintenance.php');

        $down = json_decode(File::get($this->storage.'/framework/down'), true);
        $this->assertSame($native->secret(), $down['secret']);
        $this->assertStringContainsString('The system is being updated', $down['template']);
        $this->assertSame(503, $down['status']);

        $native->up();

        $this->assertFalse($native->isDown());
        $this->assertFileDoesNotExist($this->storage.'/framework/maintenance.php');
    }

    public function test_the_secret_is_the_same_every_time_and_depends_on_the_app_key(): void
    {
        $native = app(NativeMaintenance::class);
        $first = $native->secret();

        $this->assertSame($first, $native->secret());

        config(['app.key' => 'base64:'.base64_encode(str_repeat('x', 32))]);
        $this->assertNotSame($first, $native->secret());
        $this->assertStringEndsWith('/'.$native->secret(), $native->bypassUrl());
    }

    public function test_the_browser_running_the_update_is_given_the_bypass_cookie_unencrypted(): void
    {
        $native = app(NativeMaintenance::class);

        $native->down();
        // Queued for the response (in a console there is none, unless a test opts in).
        $this->assertNotEmpty(Cookie::getQueuedCookies());
        $queued = Cookie::getQueuedCookies()[0];
        $this->assertSame('laravel_maintenance', $queued->getName());

        // The way the stub reads it: plain PHP, no decryption.
        $payload = json_decode(base64_decode($queued->getValue()), true);
        $this->assertSame(hash_hmac('sha256', $payload['expires_at'], $native->secret()), $payload['mac']);

        $this->assertTrue(app(\Illuminate\Cookie\Middleware\EncryptCookies::class)->isDisabled('laravel_maintenance'));
    }

    public function test_the_update_steps_take_the_site_down_and_bring_it_back(): void
    {
        $updater = app(Updater::class);
        $updater->start('1.2.0');
        $this->complete($updater, ['preflight']);

        $this->assertTrue($updater->runNextStep()); // maintenance

        $this->assertTrue(app()->isDownForMaintenance());
        $this->assertStringContainsString($this->storage.'/framework', str_replace('\\', '/', storage_path('framework')));
        $this->assertStringContainsString(app(NativeMaintenance::class)->secret(), $updater->state()['log']);

        $this->complete($updater, ['preflight', 'maintenance', 'code', 'dependencies', 'database', 'permissions', 'cleanup', 'optimize']);
        $this->assertTrue($updater->runNextStep()); // finish

        $this->assertFalse(app()->isDownForMaintenance());
        $this->assertFalse(Setting::get('app_offline'));
    }

    public function test_abandoning_a_failed_update_brings_the_site_back_up(): void
    {
        $updater = app(Updater::class);
        $updater->start('1.2.0');
        $this->complete($updater, ['preflight']);
        $updater->runNextStep();
        $this->assertTrue(app()->isDownForMaintenance());

        $updater->abandon();

        $this->assertFalse(app()->isDownForMaintenance());
    }

    public function test_a_site_that_was_down_before_the_update_stays_down_after_it(): void
    {
        $this->artisan('down');
        $this->assertTrue(app()->isDownForMaintenance());

        $updater = app(Updater::class);
        $updater->start('1.2.0');
        $this->complete($updater, ['preflight']);
        $updater->runNextStep();

        $updater->abandon();

        $this->assertTrue(app()->isDownForMaintenance());
    }

    /**
     * @param  list<string>  $completed
     */
    private function complete(Updater $updater, array $completed): void
    {
        $state = $updater->state();
        $state['completed'] = $completed;
        Setting::set(Updater::STATE_KEY, $state, 'system', 'json');
    }
}
