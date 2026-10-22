<?php

namespace App\Jobs;

use App\Models\MatterEmail;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Something done to a received email that can take a minute — its files to a matter and its OneDrive, or its
 * PDF made again from the mailbox — on the queue, so the page does not hang on it. One at a time on an email:
 * while one runs the email says so (busy()) and nothing else is started on it. Whoever started it is told in
 * their notifications what came of it — or, with nothing running the queue in the background, it is done at
 * once and told on their page.
 */
abstract class EmailJob implements ShouldQueue
{
    use Queueable;

    /** start(): already being worked on; queued; done at once. */
    public const BUSY = 'busy';

    public const QUEUED = 'queued';

    public const DONE = 'done';

    public int $tries = 1;

    public int $timeout = 300;

    /** What came of it, when done at once: shown on the page. */
    private ?Notification $outcome = null;

    /** @param  bool  $tell  whether whoever started it is told in their notifications (not when done at once) */
    public function __construct(public int $emailId, public ?int $userId = null, public bool $tell = true) {}

    /** Its work on the email; what came of it. */
    abstract protected function work(MatterEmail $email): ?Notification;

    /** Its title when it fails. */
    abstract protected function failedTitle(): string;

    /** What the email says while it runs ("Being filed…"). */
    abstract protected function doing(): string;

    /** @return self::BUSY|self::QUEUED|self::DONE */
    public function start(): string
    {
        if (! Cache::add(self::key($this->emailId), $this->doing(), now()->addMinutes(15))) {
            return self::BUSY;
        }

        if (config('queue.default') === 'sync') {
            $this->tell = false;
            $this->handle();
            $this->outcome?->send();

            return self::DONE;
        }

        dispatch($this);

        return self::QUEUED;
    }

    /** What is being done to the email now, if anything ("Being filed…"). */
    public static function busy(int $emailId): ?string
    {
        $doing = Cache::get(self::key($emailId));

        return is_string($doing) ? $doing : null;
    }

    /** Under an email's subject in a list: what is being done to it now, else how many files it came with. */
    public static function note(MatterEmail $email): ?string
    {
        $files = count($email->attachmentFiles());

        return self::busy((int) $email->getKey()) ?? ($files ? trans_choice(':count file|:count files', $files, ['count' => $files]) : null);
    }

    public function handle(): void
    {
        try {
            if ($email = MatterEmail::find($this->emailId)) {
                $this->told($this->work($email));
            }
        } catch (Throwable $e) {
            Log::warning(static::class.' failed', ['email' => $this->emailId, 'error' => $e->getMessage()]);

            $this->told(Notification::make()->danger()->title($this->failedTitle())->body($e->getMessage()));
        } finally {
            Cache::forget(self::key($this->emailId));
        }
    }

    private function told(?Notification $notification): void
    {
        $this->outcome = $notification;

        if ($notification && $this->tell && $user = $this->userId ? User::find($this->userId) : null) {
            $notification->sendToDatabase($user);
        }
    }

    private static function key(int $emailId): string
    {
        return 'email-job.'.$emailId;
    }
}
