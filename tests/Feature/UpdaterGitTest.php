<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\Updater\Updater;
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
        File::put($work.'/.htaccess', "RewriteEngine On\nRULES v1\n");
        File::put($work.'/app.txt', "v1\n");
        File::put($work.'/public/js/asset.js', "asset v1\n");
        $this->commitAndTag($work, 'v1.0.0');

        File::put($work.'/.htaccess', "RewriteEngine On\nRULES v2\n");
        File::put($work.'/app.txt', "v2\n");
        $this->commitAndTag($work, 'v1.1.0');
        $this->git($work, ['push', 'origin', 'main', '--tags']);

        // The deployed site: installed at 1.0.0, then changed locally.
        $this->git($this->root, ['clone', 'origin.git', 'site']);
        $this->git($this->site, ['-c', 'advice.detachedHead=false', 'checkout', 'v1.0.0']);
        File::put($this->site.'/.htaccess', "# Force PHP 8.5\nAddHandler x-php85 .php\n\n".File::get($this->site.'/.htaccess'));
        File::put($this->site.'/public/js/asset.js', "asset republished\n");

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
            "# Force PHP 8.5\nAddHandler x-php85 .php\n\nRewriteEngine On\nRULES v2\n",
            str_replace("\r\n", "\n", File::get($this->site.'/.htaccess')),
        );
        $this->assertSame("asset v1\n", str_replace("\r\n", "\n", File::get($this->site.'/public/js/asset.js')));
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
