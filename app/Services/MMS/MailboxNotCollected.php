<?php

namespace App\Services\MMS;

use RuntimeException;
use Throwable;

/**
 * A mailbox whose emails could not be read this time (MatterReplyCollector): recorded in the error log (Settings →
 * Error tracking) with the mailbox, the reason, and the error behind it with its trace — not only "the scheduled
 * command failed". The other mailboxes are still read, and this one again on the next run.
 */
final class MailboxNotCollected extends RuntimeException
{
    public static function for(string $mailbox, Throwable $error): self
    {
        return new self('Emails not collected from mailbox "'.$mailbox.'": '.$error::class.': '.$error->getMessage(), 0, $error);
    }
}
