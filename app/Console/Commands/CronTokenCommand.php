<?php

namespace App\Console\Commands;

use App\Services\Installer\EnvironmentFileWriter;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Creates the secret for the web cron trigger and prints the URL to give
 * an outside cron service (such as cron-job.org), called every minute.
 */
class CronTokenCommand extends Command
{
    protected $signature = 'cron:token {--show : Print the current URL without making a new token}';

    protected $description = 'Create the token for the web cron trigger (/cron/run) and print its URL';

    public function handle(): int
    {
        $token = (string) config('services.cron.token');

        if (! $this->option('show') || strlen($token) < 32) {
            $token = Str::random(48);
            (new EnvironmentFileWriter)->set(['CRON_TOKEN' => $token]);
            config(['services.cron.token' => $token]);
            $this->components->info('A new token was saved to .env as CRON_TOKEN — any old URL stops working.');
        }

        $this->line('');
        $this->line('Call this URL every minute (cron expression: * * * * *):');
        $this->line(rtrim((string) config('app.url'), '/').'/cron/run?token='.$token);
        $this->line('');
        $this->comment('If configuration is cached, run: php artisan config:clear');

        return self::SUCCESS;
    }
}
