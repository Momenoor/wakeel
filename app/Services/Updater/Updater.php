<?php

namespace App\Services\Updater;

use App\Models\License;
use App\Models\Setting;
use App\Services\License\LicenseVerifier;
use App\Support\AppUpdate;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * One-click update to a published release: checks out git tag
 * `v{version}`, installs its Composer dependencies, migrates, and refreshes
 * permissions and caches — one step per call, so the System Updates page
 * can run each as its own request and show progress (the same approach as
 * the installer and install.php; a single request would outlive typical
 * shared-hosting timeouts).
 *
 * The site sits in the app's own offline mode for the duration, which
 * still lets administrators in to watch it — and to retry a failed step or
 * bring the site back up. Progress is kept in the `updater_state` setting,
 * so a reload (or a Livewire version change mid-update) can resume.
 */
class Updater
{
    public const STATE_KEY = 'updater_state';

    /**
     * @return array<string, string> step key => label, in run order
     */
    public function steps(): array
    {
        return [
            'preflight' => __('Check the server can update'),
            'maintenance' => __('Switch to maintenance mode'),
            'code' => __('Download the new version'),
            'dependencies' => __('Install PHP dependencies'),
            'database' => __('Update the database'),
            'permissions' => __('Refresh permissions'),
            'cleanup' => __('Clear caches'),
            'finish' => __('Bring the site back online'),
        ];
    }

    /**
     * @return array{version: string, completed: list<string>, failed: bool, log: string}|null
     */
    public function state(): ?array
    {
        $state = Setting::get(self::STATE_KEY);

        return is_array($state) ? $state : null;
    }

    public function start(string $version): void
    {
        $this->saveState(['version' => $version, 'completed' => [], 'failed' => false, 'log' => '']);
    }

    /**
     * Runs the next pending step. Returns false once there is nothing left.
     */
    public function runNextStep(): bool
    {
        $state = $this->state();

        if ($state === null || $state['failed']) {
            return false;
        }

        $pending = array_values(array_diff(array_keys($this->steps()), $state['completed']));

        if ($pending === []) {
            return false;
        }

        $step = $pending[0];

        @set_time_limit(0);
        @ignore_user_abort(true);

        $log = '== '.$this->steps()[$step]." ==\n";

        try {
            $log .= $this->runStep($step, $state['version']);
            $state['completed'][] = $step;
        } catch (Throwable $e) {
            $log .= $e->getMessage()."\n";
            $state['failed'] = true;
        }

        $state['log'] = mb_substr($state['log'].$log."\n", -20000);
        $this->saveState($state);

        // Once finished, nothing is left to resume.
        if (in_array('finish', $state['completed'], true)) {
            Setting::forget(self::STATE_KEY);
        }

        return ! $state['failed'];
    }

    public function retry(): void
    {
        $state = $this->state();

        if ($state !== null) {
            $state['failed'] = false;
            $this->saveState($state);
        }
    }

    /**
     * Give up on a failed update: back online, whatever state the code is
     * in. Retrying (or updating again) stays possible.
     */
    public function abandon(): void
    {
        $this->restoreOfflineMode();
        Setting::forget(self::STATE_KEY);
    }

    private function runStep(string $step, string $version): string
    {
        return match ($step) {
            'preflight' => $this->preflight($version),
            'maintenance' => $this->enterMaintenance($version),
            'code' => $this->checkout($version),
            'dependencies' => $this->composerInstall(),
            'database' => $this->artisan('migrate', ['--force' => true]),
            'permissions' => $this->refreshPermissions(),
            'cleanup' => $this->artisan('optimize:clear'),
            'finish' => $this->finish(),
        };
    }

    private function preflight(string $version): string
    {
        if (! function_exists('proc_open')) {
            throw new RuntimeException(__('The proc_open function is disabled on this server, so it cannot run git or Composer.'));
        }

        if (! is_dir(base_path('.git'))) {
            throw new RuntimeException(__('This installation is not a git checkout, so it cannot be updated in place.'));
        }

        $output = 'PHP: '.implode(' ', $this->php())."\n";
        $output .= 'Composer: '.implode(' ', $this->composer())."\n";
        $output .= $this->git(['fetch', '--tags', '--force', 'origin']);

        if (! $this->process([...$this->gitCommand(), 'rev-parse', '-q', '--verify', "refs/tags/v{$version}"])['ok']) {
            throw new RuntimeException(__('Release tag v:version was not found on the repository.', ['version' => $version]));
        }

        $output .= "Found tag v{$version}.\n";

        // Local edits would be overwritten (or block the checkout). Only
        // two kinds are expected, and checkout() handles both: the PHP
        // handler install.php adds to .htaccess, and files the server's own
        // Composer/npm runs regenerate (see isGenerated()).
        $unexpected = array_filter(
            $this->changedFiles(),
            fn (string $file): bool => $file !== '.htaccess' && ! $this->isGenerated($file),
        );

        if ($unexpected !== []) {
            throw new RuntimeException(__('These files were changed on the server and would be overwritten: :files', ['files' => implode(', ', $unexpected)]));
        }

        return $output;
    }

    private function enterMaintenance(string $version): string
    {
        // Only the first time — a retried step must not record the
        // updater's own maintenance mode as the "previous" state.
        if (Setting::get('updater_previous_offline') === null) {
            Setting::set('updater_previous_offline', [
                'app_offline' => (bool) Setting::get('app_offline', false),
                'offline_message' => Setting::get('offline_message'),
            ], 'system', 'json');
        }

        Setting::set('app_offline', true, 'system');
        Setting::set('offline_message', __('The system is being updated to version :version. Please check back in a few minutes.', ['version' => $version]), 'system');

        return __('Maintenance mode is on — administrators can still sign in.')."\n";
    }

    private function checkout(string $version): string
    {
        $htaccess = base_path('.htaccess');
        $prefix = null;
        $changed = $this->changedFiles();

        // Regenerated files: the release's own copies replace them.
        $generated = array_values(array_filter($changed, $this->isGenerated(...)));

        if ($generated !== []) {
            $this->git(['checkout', '--', ...$generated]);
        }

        // A frontend built on the server leaves new, untracked hashed files
        // in public/build; one that the release also ships would make git
        // refuse the checkout rather than overwrite it.
        if (is_dir(base_path('public/build'))) {
            $this->git(['clean', '-f', '-q', '--', 'public/build']);
        }

        // install.php prepends a PHP-version handler to the tracked
        // .htaccess. Keep exactly that prefix across the checkout.
        if (in_array('.htaccess', $changed, true)) {
            $local = str_replace("\r\n", "\n", (string) file_get_contents($htaccess));
            $tracked = str_replace("\r\n", "\n", $this->git(['show', 'HEAD:.htaccess']));

            if (! str_ends_with($local, $tracked)) {
                throw new RuntimeException(__('.htaccess has local edits beyond the PHP handler at its top — merge them by hand, then retry.'));
            }

            $prefix = substr($local, 0, strlen($local) - strlen($tracked));
            $this->git(['checkout', '--', '.htaccess']);
        }

        $output = $this->git(['-c', 'advice.detachedHead=false', 'checkout', "v{$version}"]);

        if ($prefix !== null && $prefix !== '') {
            file_put_contents($htaccess, $prefix.file_get_contents($htaccess));
            $output .= __('Restored the PHP handler at the top of .htaccess.')."\n";
        }

        return $output;
    }

    private function composerInstall(): string
    {
        $result = $this->process(
            [...$this->composer(), 'install', '--no-dev', '--no-interaction', '--optimize-autoloader', '--no-ansi'],
            timeout: 1800,
        );

        if (! $result['ok'] || ! file_exists(base_path('vendor/autoload.php'))) {
            throw new RuntimeException($result['output']."\n".__('Composer install failed.'));
        }

        return $result['output'];
    }

    private function refreshPermissions(): string
    {
        $output = '';

        foreach (array_keys(array_filter(['mms' => config('modules.mms'), 'pms' => config('modules.pms')])) as $panel) {
            $output .= $this->artisan('shield:generate', ['--panel' => $panel, '--option' => 'permissions', '--all' => true]);
        }

        return $output;
    }

    private function finish(): string
    {
        $this->restoreOfflineMode();

        // Reports the new version to the license server right away.
        $license = License::current();

        if ($license !== null) {
            app(LicenseVerifier::class)->verify($license);
        }

        Setting::set('last_updated_at', now()->toDateTimeString(), 'system');

        return __('Updated to version :version.', ['version' => AppUpdate::currentVersion()])."\n";
    }

    private function restoreOfflineMode(): void
    {
        $previous = Setting::get('updater_previous_offline');

        if (is_array($previous)) {
            Setting::set('app_offline', (bool) ($previous['app_offline'] ?? false), 'system');
            Setting::set('offline_message', $previous['offline_message'] ?? null, 'system');
            Setting::forget('updater_previous_offline');
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function artisan(string $command, array $arguments = []): string
    {
        $exitCode = Artisan::call($command, $arguments);
        $output = Artisan::output();

        if ($exitCode !== 0) {
            throw new RuntimeException($output."\n".__('":command" failed.', ['command' => $command]));
        }

        return $output;
    }

    /**
     * Files the server's own tooling rewrites, never edited by hand:
     * package assets `filament:upgrade` publishes on every `composer
     * install`, the frontend build and npm's lock file (install.php builds
     * on the server), and composer.lock (Composer may rewrite it when it
     * runs there). The release ships its own copy of each, and the next
     * step installs exactly what its composer.lock pins.
     */
    private function isGenerated(string $file): bool
    {
        return in_array($file, ['composer.lock', 'package-lock.json'], true)
            || preg_match('#^public/(js|css|fonts|vendor|build)/#', $file) === 1;
    }

    /**
     * @return list<string>
     */
    private function changedFiles(): array
    {
        // Porcelain lines are "XY path" — the status columns can start with
        // a space, so lines are never trimmed before cutting them off.
        return array_values(array_filter(array_map(
            fn (string $line): string => trim(substr(rtrim($line, "\r"), 3)),
            explode("\n", $this->git(['status', '--porcelain', '--untracked-files=no'])),
        )));
    }

    /**
     * @param  list<string>  $arguments
     */
    private function git(array $arguments): string
    {
        $result = $this->process([...$this->gitCommand(), ...$arguments]);

        if (! $result['ok']) {
            throw new RuntimeException($result['output']."\n".__('git :command failed.', ['command' => $arguments[0]]));
        }

        return $result['output'];
    }

    /**
     * @return list<string>
     */
    private function gitCommand(): array
    {
        $git = (new ExecutableFinder)->find('git');

        if ($git === null) {
            throw new RuntimeException(__('git was not found on this server.'));
        }

        // The web server's user may not own the checkout; trust this one
        // repository only, rather than turning the check off globally.
        return [$git, '-c', 'safe.directory='.str_replace('\\', '/', base_path())];
    }

    /**
     * The PHP command-line binary. Under a web server PHP_BINARY is the
     * FastCGI/LiteSpeed handler, which can't run scripts like Composer —
     * the same lookup install.php does.
     *
     * @return list<string>
     */
    private function php(): array
    {
        if (PHP_SAPI === 'cli' && PHP_BINARY !== '') {
            return [PHP_BINARY];
        }

        $version = PHP_MAJOR_VERSION.PHP_MINOR_VERSION;

        foreach ([
            "/opt/cpanel/ea-php{$version}/root/usr/bin/php",
            "/opt/alt/php{$version}/usr/bin/php",
            "/usr/local/bin/php{$version}",
            "/usr/bin/php{$version}",
        ] as $path) {
            if (is_executable($path)) {
                return [$path];
            }
        }

        $php = (new ExecutableFinder)->find('php');

        if ($php === null) {
            throw new RuntimeException(__('The PHP command-line binary was not found on this server.'));
        }

        return [$php];
    }

    /**
     * @return list<string>
     */
    private function composer(): array
    {
        if (is_file(base_path('composer.phar'))) {
            return [...$this->php(), base_path('composer.phar')];
        }

        $composer = (new ExecutableFinder)->find('composer');

        if ($composer === null) {
            throw new RuntimeException(__('Composer was not found on this server. Run install.php once to set it up, or install Composer.'));
        }

        // Windows ships a .bat wrapper; elsewhere run the phar through the
        // PHP binary we picked, not whatever `php` Composer's shebang finds.
        return preg_match('/\.(bat|cmd|exe)$/i', $composer) === 1
            ? [$composer]
            : [...$this->php(), $composer];
    }

    /**
     * @param  list<string>  $command
     * @return array{ok: bool, output: string}
     */
    private function process(array $command, int $timeout = 300): array
    {
        $env = [];

        // Web requests often run without HOME, which git and Composer need.
        if (getenv('HOME') === false || getenv('HOME') === '') {
            $home = storage_path('app/updater-home');
            @mkdir($home, 0755, true);
            $env = ['HOME' => $home, 'COMPOSER_HOME' => $home.'/.composer'];
        }

        $process = new Process($command, base_path(), $env, null, $timeout);
        $process->run();

        return [
            'ok' => $process->isSuccessful(),
            'output' => $process->getOutput().$process->getErrorOutput(),
        ];
    }

    private function saveState(array $state): void
    {
        Setting::set(self::STATE_KEY, $state, 'system', 'json');
    }
}
