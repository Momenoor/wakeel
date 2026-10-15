<?php

namespace Tests\Feature;

use App\Models\MailSender;
use App\Services\MMS\SenderMailer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The mailboxes that were set in config/mail_senders.php move into the database, which is the only place senders are kept.
 */
class MailSendersMovedToDatabaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_config_no_longer_holds_senders(): void
    {
        $this->assertArrayNotHasKey('senders', config('mail_senders'));
        $this->assertSame(3, config('mail_senders.retry_attempts'));
    }

    public function test_senders_with_a_password_in_env_are_moved_into_the_database_with_their_signature(): void
    {
        $this->assertSame(0, MailSender::count());

        // As on a server: not a test database, the password in .env.
        $env = $this->app['env'];
        $this->app['env'] = 'production';
        $passwords = ['MAIL_SENDER_IFLAS_PASSWORD' => 'real-password', 'MAIL_SENDER_HAIKALA_PASSWORD' => '', 'MAIL_SENDER_EASAR_PASSWORD' => ''];
        $before = array_map(fn (string $name) => $_ENV[$name] ?? null, array_keys($passwords));
        foreach ($passwords as $name => $value) {
            putenv("{$name}={$value}");
            $_ENV[$name] = $_SERVER[$name] = $value;
        }

        try {
            (require database_path('migrations/2020_01_01_000154_move_config_mail_senders_to_database.php'))->up();
            // Again: nothing doubled.
            (require database_path('migrations/2020_01_01_000154_move_config_mail_senders_to_database.php'))->up();
        } finally {
            foreach (array_keys($passwords) as $i => $name) {
                putenv($name);
                unset($_SERVER[$name]);
                $before[$i] === null ? \Illuminate\Support\Arr::forget($_ENV, $name) : $_ENV[$name] = $before[$i];
            }
            $this->app['env'] = $env;
        }

        // Only the one whose password was there.
        $iflas = MailSender::sole();
        $this->assertSame('iflas', $iflas->key);
        $this->assertSame('real-password', $iflas->password);
        $this->assertSame('mail.jpaemirates.com', $iflas->host);
        $this->assertStringContainsString('Best Regards', (string) $iflas->signature);
        $this->assertTrue($iflas->is_active);

        // Offered to send from, signature and all.
        $this->assertArrayHasKey('iflas', SenderMailer::options());
        $this->assertStringContainsString('Best Regards', SenderMailer::sender('iflas')['signature']);
    }
}
