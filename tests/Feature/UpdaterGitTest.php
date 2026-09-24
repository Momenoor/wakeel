<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\Updater\Updater;
use App\Support\AppUpdate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * The updater's git steps against a real, throwaway repository — a bare
 * "origin" with tags v1.0.0 and v1.1.0, and a "site" checkout at v1.0.0
 * carrying the local changes a real server has: install.php's PHP handler
 * at the top of .htaccess, and a package asset Composer republished. The
 * application's own repository is never touched: base_path() points at
 * the throwaway site for the duration of each test.
 */
class UpdaterGitTest extends TestCase
{
    use RefreshDatabase;

    /** What install.php prepends to .htaccess on cPanel/LiteSpeed. */
    private const HANDLER = "# Force PHP 8.5 on LiteSpeed / EasyApache 4\n<IfModule mime_module>\n  AddHandler application/x-httpd-ea-php85___lsphp .php .php8 .phtml\n</IfModule>\n\n";

    private string $root;

    private string $site;

    private string $originalBasePath;

    protected function setUp(): void
    {
        parent::setUp();

        if ((new ExecutableFinder)->find('git') === null) {
            $this->markTestSkipped('git is not installed.');
        }

        $this->root = str_replace('\\', '/', sys_get_temp_dir()).'/updater-git-test-'.uniqid();
        $this->site = $this->root.'/site';
        $work = $this->root.'/work';

        File::ensureDirectoryExists($this->root);
        $this->git($this->root, ['init', '--bare', '-b', 'main', 'origin.git']);
        $this->git($this->root, ['clone', 'origin.git', 'work']);

        File::ensureDirectoryExists($work.'/public/js');
        File::ensureDirectoryExists($work.'/public/build/assets');
        File::ensureDirectoryExists($work.'/config');
        File::put($work.'/.htaccess', "RewriteEngine On\nRULES v1\n");
        File::put($work.'/app.txt', "v1\n");
        File::put($work.'/public/js/asset.js', "asset v1\n");
        File::put($work.'/composer.lock', "lock v1\n");
        File::put($work.'/public/build/manifest.json', "manifest v1\n");
        $this->declareVersion($work, '1.0.0');
        $this->commitAndTag($work, 'v1.0.0');

        File::put($work.'/.htaccess', "RewriteEngine On\nRULES v2\n");
        File::put($work.'/app.txt', "v2\n");
        File::put($work.'/composer.lock', "lock v2\n");
        File::put($work.'/public/build/manifest.json', "manifest v2\n");
        File::put($work.'/public/build/assets/app-2.js', "release build\n");
        $this->declareVersion($work, '1.1.0');
        $this->commitAndTag($work, 'v1.1.0');

        // Cut without bumping the version — what happened to v1.0.2.
        File::put($work.'/app.txt', "v3\n");
        $this->commitAndTag($work, 'v1.2.0');

        $this->git($work, ['push', 'origin', 'main', '--tags']);

        // The deployed site: installed at 1.0.0, then changed locally.
        $this->git($this->root, ['clone', 'origin.git', 'site']);
        $this->git($this->site, ['-c', 'advice.detachedHead=false', 'checkout', 'v1.0.0']);
        File::put($this->site.'/.htaccess', self::HANDLER.File::get($this->site.'/.htaccess'));
        File::put($this->site.'/public/js/asset.js', "asset republished\n");
        // Composer and npm run on the server: a rewritten lock file and a
        // local frontend build — including an untracked file the release
        // also ships.
        File::put($this->site.'/composer.lock', "lock rewritten on the server\n");
        File::put($this->site.'/public/build/manifest.json', "manifest built on the server\n");
        File::ensureDirectoryExists($this->site.'/public/build/assets');
        File::put($this->site.'/public/build/assets/app-2.js', "built on the server\n");

        $this->originalBasePath = $this->app->basePath();
        $this->app->setBasePath($this->site);
    }

    protected function tearDown(): void
    {
        if (isset($this->originalBasePath)) {
            $this->app->setBasePath($this->originalBasePath);
        }

        Setting::clearCache();

        if (isset($this->root)) {
            // Git marks pack files read-only, which File::deleteDirectory
            // can't remove on Windows.
            foreach (File::allFiles($this->root, true) as $file) {
                @chmod($file->getPathname(), 0666);
            }
            File::deleteDirectory($this->root);
        }

        parent::tearDown();
    }

    public function test_it_checks_out_the_release_tag_and_keeps_the_php_handler(): void
    {
        $updater = app(Updater::class);
        $updater->start('1.1.0');

        $this->assertTrue($updater->runNextStep(), (string) $updater->state()['log']); // preflight
        $this->assertTrue($updater->runNextStep(), (string) $updater->state()['log']); // maintenance
        $this->assertTrue($updater->runNextStep(), (string) $updater->state()['log']); // code

        $this->assertSame("v2\n", str_replace("\r\n", "\n", File::get($this->site.'/app.txt')));
        $this->assertSame(
            self::HANDLER."RewriteEngine On\nRULES v2\n",
            str_replace("\r\n", "\n", File::get($this->site.'/.htaccess')),
        );
        $this->assertSame("asset v1\n", str_replace("\r\n", "\n", File::get($this->site.'/public/js/asset.js')));

        // Server-regenerated files are replaced by the release's own.
        $this->assertSame("lock v2\n", str_replace("\r\n", "\n", File::get($this->site.'/composer.lock')));
        $this->assertSame("manifest v2\n", str_replace("\r\n", "\n", File::get($this->site.'/public/build/manifest.json')));
        $this->assertSame("release build\n", str_replace("\r\n", "\n", File::get($this->site.'/public/build/assets/app-2.js')));
    }

    /**
     * What stopped the live update: a newer .htaccess uploaded by hand, so
     * the file isn't "handler + the committed one". The handler is kept,
     * the rest comes from the release, and the old file is backed up.
     */
    public function test_a_hand_edited_htaccess_is_replaced_keeping_the_php_handler(): void
    {
        File::put($this->site.'/.htaccess', self::HANDLER."RewriteEngine On\nRULES UPLOADED BY HAND\n");

        $updater = app(Updater::class);
        $updater->start('1.1.0');

        $this->assertTrue($updater->runNextStep(), (string) $updater->state()['log']); // preflight
        $this->assertTrue($updater->runNextStep(), (string) $updater->state()['log']); // maintenance
        $this->assertTrue($updater->runNextStep(), (string) $updater->state()['log']); // code

        $this->assertSame(self::HANDLER."RewriteEngine On\nRULES v2\n", str_replace("\r\n", "\n", File::get($this->site.'/.htaccess')));

        $backups = File::glob($this->site.'/storage/app/updater/htaccess-*');
        $this->assertCount(1, $backups);
        $this->assertStringContainsString('RULES UPLOADED BY HAND', File::get($backups[0]));
    }

    public function test_preflight_refuses_to_overwrite_other_local_changes(): void
    {
        File::put($this->site.'/app.txt', "edited on the server\n");

        $updater = app(Updater::class);
        $updater->start('1.1.0');

        $this->assertFalse($updater->runNextStep());
        $this->assertStringContainsString('app.txt', $updater->state()['log']);
        $this->assertSame("edited on the server\n", File::get($this->site.'/app.txt'));
    }

    public function test_preflight_fails_for_a_release_that_was_never_tagged(): void
    {
        $updater = app(Updater::class);
        $updater->start('9.9.9');

        $this->assertFalse($updater->runNextStep());
        $this->assertStringContainsString('v9.9.9', $updater->state()['log']);
    }

    public function test_after_updating_the_app_reports_the_tag_even_if_config_was_not_bumped(): void
    {
        config(['license.version_from_git' => true]);

        $this->assertSame('1.0.0', AppUpdate::currentVersion());

        $updater = app(Updater::class);
        $updater->start('1.2.0');

        $this->assertTrue($updater->runNextStep(), (string) $updater->state()['log']); // preflight
        $this->assertTrue($updater->runNextStep(), (string) $updater->state()['log']); // maintenance
        $this->assertTrue($updater->runNextStep(), (string) $updater->state()['log']); // code

        // v1.2.0's config still says 1.1.0 — the tag is what counts.
        $this->assertSame('1.2.0', AppUpdate::currentVersion());
    }

    private function declareVersion(string $dir, string $version): void
    {
        File::put($dir.'/config/license.php', "<?php\n\nreturn [\n    'app_version' => '{$version}',\n];\n");
    }

    private function commitAndTag(string $dir, string $tag): void
    {
        $this->git($dir, ['add', '-A']);
        $this->git($dir, ['-c', 'user.name=Test', '-c', 'user.email=test@example.com', 'commit', '-m', $tag]);
        $this->git($dir, ['tag', $tag]);
    }

    /**
     * @param  list<string>  $arguments
     */
    private function git(string $cwd, array $arguments): void
    {
        $process = new Process(['git', '-c', 'core.autocrlf=false', ...$arguments], $cwd);
        $process->mustRun();
    }
}
