<?php

namespace Tests\Unit;

use App\Support\AppUpdate;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * AppUpdate::versionFromGit() reads .git's files directly — each layout
 * git can leave them in, built by hand.
 */
class AppVersionFromGitTest extends TestCase
{
    private const COMMIT = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const OTHER = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private string $git;

    protected function setUp(): void
    {
        parent::setUp();

        $this->git = sys_get_temp_dir().'/app-version-test-'.uniqid();
        File::ensureDirectoryExists($this->git.'/refs/tags');
        File::ensureDirectoryExists($this->git.'/refs/heads');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->git);

        parent::tearDown();
    }

    public function test_a_detached_head_on_a_loose_tag(): void
    {
        File::put($this->git.'/HEAD', self::COMMIT."\n");
        File::put($this->git.'/refs/tags/v1.2.0', self::COMMIT."\n");

        $this->assertSame('1.2.0', AppUpdate::versionFromGit($this->git));
    }

    public function test_the_highest_of_several_tags_on_the_same_commit(): void
    {
        File::put($this->git.'/HEAD', self::COMMIT."\n");
        File::put($this->git.'/packed-refs', "# pack-refs with: peeled fully-peeled sorted\n"
            .self::COMMIT." refs/tags/v1.0.3\n"
            .self::COMMIT." refs/tags/v1.0.4\n"
            .self::OTHER." refs/tags/v9.0.0\n");
        File::put($this->git.'/refs/tags/v1.0.10', self::COMMIT."\n");

        $this->assertSame('1.0.10', AppUpdate::versionFromGit($this->git));
    }

    public function test_an_annotated_tag_resolves_through_its_peeled_line(): void
    {
        File::put($this->git.'/HEAD', self::COMMIT."\n");
        File::put($this->git.'/packed-refs', self::OTHER." refs/tags/v2.0.0\n^".self::COMMIT."\n");

        $this->assertSame('2.0.0', AppUpdate::versionFromGit($this->git));
    }

    public function test_a_branch_checkout_on_a_tagged_commit(): void
    {
        File::put($this->git.'/HEAD', "ref: refs/heads/main\n");
        File::put($this->git.'/packed-refs', self::COMMIT." refs/heads/main\n".self::COMMIT." refs/tags/v1.1.0\n");

        $this->assertSame('1.1.0', AppUpdate::versionFromGit($this->git));
    }

    public function test_an_untagged_commit_or_no_repository_gives_nothing(): void
    {
        File::put($this->git.'/HEAD', self::COMMIT."\n");
        File::put($this->git.'/refs/tags/v1.0.0', self::OTHER."\n");
        File::put($this->git.'/refs/tags/not-a-version', self::COMMIT."\n");

        $this->assertNull(AppUpdate::versionFromGit($this->git));
        $this->assertNull(AppUpdate::versionFromGit($this->git.'/missing'));
    }

    public function test_config_is_the_fallback(): void
    {
        config(['license.version_from_git' => true, 'license.app_version' => '3.3.3']);
        $this->app->setBasePath(dirname($this->git));

        try {
            $this->assertSame('3.3.3', AppUpdate::currentVersion());
        } finally {
            $this->app->setBasePath(dirname(__DIR__, 2));
        }
    }
}
