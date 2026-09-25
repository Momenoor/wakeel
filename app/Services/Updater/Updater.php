<?php

namespace App\Services\Updater;

use App\Models\License;
use App\Models\Setting;
use App\Services\License\LicenseVerifier;
use App\Support\AppUpdate;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
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

    private const LOCK = 'updater:step';

    /**
     * Seconds without any output after which a running step counts as dead
     * (see runNextStepIfIdle()). Composer prints steadily; git's network
     * calls abort after 60 silent seconds (see gitCommand()).
     */
    public const STALE_AFTER = 600;

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
     * runNextStep(), unless a step is already running. The page calls this
     * again after its previous request failed — typically the web server
     * timing out a long `composer install` that PHP carries on with — so it
     * must never start the same step twice.
     *
     * @return 'ran'|'stopped'|'busy'
     */
    public function runNextStepIfIdle(): string
    {
        try {
            $lock = Cache::lock(self::LOCK, 1800);

            if (! $lock->get()) {
                // A step that has gone silent is dead: the web server killed
                // its request, so it never released the lock. Take over
                // rather than answering 'busy' until the lock expires.
                if (($this->secondsSinceOutput() ?? 0) < self::STALE_AFTER) {
                    return 'busy';
                }

                Cache::lock(self::LOCK)->forceRelease();

                if (! $lock->get()) {
                    return 'busy';
                }
            }
        } catch (Throwable) {
            $lock = null; // Cache store without locks.
        }

        try {
            return $this->runNextStep() ? 'ran' : 'stopped';
        } finally {
            $lock?->release();
        }
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

        // Each step starts a fresh live log.
        @mkdir(dirname($this->liveLogPath()), 0755, true);
        @file_put_contents($this->liveLogPath(), $log);

        try {
            $log .= $this->runStep($step, $state['version']);
            $state['completed'][] = $step;
        } catch (Throwable $e) {
            $log .= $e->getMessage()."\n";
            $state['failed'] = true;
        }

        // Composer's own scripts (package:discover --ansi) force terminal
        // colour codes, which the page would show as "[90m…" noise.
        $log = (string) preg_replace('/\e\[[0-9;]*[A-Za-z]/', '', $log);

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

        // The release's .htaccess replaces the server's, except for the
        // PHP-version handler install.php adds — without it the host could
        // fall back to an older PHP mid-update. Anything else edited there
        // (e.g. rules uploaded by hand) is kept in a backup, not merged.
        if (in_array('.htaccess', $changed, true)) {
            $local = (string) file_get_contents($htaccess);
            $prefix = static::phpHandlerBlocks($local);

            $backup = storage_path('app/updater/htaccess-'.now()->format('Ymd-His'));
            @mkdir(dirname($backup), 0755, true);
            file_put_contents($backup, $local);

            $this->git(['checkout', '--', '.htaccess']);
        }

        $output = $this->git(['-c', 'advice.detachedHead=false', 'checkout', "v{$version}"]);

        if ($prefix !== null) {
            $output .= __('Replaced .htaccess with this release\'s; the previous one is saved in storage/app/updater/.')."\n";
        }

        if ($prefix !== null && $prefix !== '') {
            file_put_contents($htaccess, $prefix.str_replace("\r\n", "\n", (string) file_get_contents($htaccess)));
            $output .= __('Restored the PHP handler at the top of .htaccess.')."\n";
        }

        return $output;
    }

    /**
     * Every `<IfModule mime_module>` block setting an AddHandler (the PHP
     * version selector hosts like cPanel/LiteSpeed use), with the comment
     * line right above it — ready to put back at the top of .htaccess.
     */
    public static function phpHandlerBlocks(string $htaccess): string
    {
        preg_match_all(
            '/(?:^#[^\n]*\n)?<IfModule mime_module>(?:(?!<\/IfModule>).)*?AddHandler(?:(?!<\/IfModule>).)*<\/IfModule>\s*/ms',
            str_replace("\r\n", "\n", $htaccess),
            $matches,
        );

        $blocks = implode('', $matches[0]);

        return $blocks === '' ? '' : rtrim($blocks)."\n\n";
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
        // repository only, rather than turning the check off globally. A
        // network transfer that stalls for 60 seconds aborts instead of
        // hanging the step (and its request) indefinitely.
        return [
            $git,
            '-c', 'safe.directory='.str_replace('\\', '/', base_path()),
            '-c', 'http.lowSpeedLimit=1000',
            '-c', 'http.lowSpeedTime=60',
        ];
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
        // Nothing may wait for a prompt no one will ever answer: git asking
        // for credentials would otherwise hang the step until the web server
        // killed its request.
        $env = ['GIT_TERMINAL_PROMPT' => '0', 'GCM_INTERACTIVE' => 'never', 'COMPOSER_NO_INTERACTION' => '1'];

        // Web requests often run without HOME, which git and Composer need.
        if (getenv('HOME') === false || getenv('HOME') === '') {
            $home = storage_path('app/updater-home');
            @mkdir($home, 0755, true);
            $env += ['HOME' => $home, 'COMPOSER_HOME' => $home.'/.composer'];
        }

        $process = new Process($command, base_path(), $env, null, $timeout);

        // Streamed to the live log as it arrives, so the page can show a
        // long `composer install` while it runs (see liveOutput()).
        $process->run(fn (string $type, string $buffer) => @file_put_contents($this->liveLogPath(), $buffer, FILE_APPEND));

        return [
            'ok' => $process->isSuccessful(),
            'output' => $process->getOutput().$process->getErrorOutput(),
        ];
    }

    /**
     * The running step's output so far (its tail), without terminal colour
     * codes — read by the System Updates page while a step runs.
     */
    public function liveOutput(int $maxLength = 8000): string
    {
        $output = (string) @file_get_contents($this->liveLogPath());

        return mb_substr((string) preg_replace('/\e\[[0-9;]*[A-Za-z]/', '', $output), -$maxLength);
    }

    /**
     * How long since the running step last produced output (or started),
     * or null when no step has run.
     */
    public function secondsSinceOutput(): ?int
    {
        clearstatcache(true, $this->liveLogPath());
        $modified = @filemtime($this->liveLogPath());

        return $modified === false ? null : max(0, time() - $modified);
    }

    private function liveLogPath(): string
    {
        return storage_path('app/updater/live.log');
    }

    private function saveState(array $state): void
    {
        Setting::set(self::STATE_KEY, $state, 'system', 'json');
    }
}
