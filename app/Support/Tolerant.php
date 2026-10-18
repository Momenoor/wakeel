<?php

namespace App\Support;

/**
 * Work done by a library that warns as it goes over what it reads — mPDF over
 * an email's HTML, the IMAP decoder over a badly encoded part ("iconv():
 * Detected an illegal character", "Undefined array key …") — let finish:
 * Laravel turns any warning into an exception, which would lose the whole
 * email for one odd byte. Errors are still errors; only warnings, notices and
 * deprecations are let pass.
 */
class Tolerant
{
    /**
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    public static function run(callable $work): mixed
    {
        set_error_handler(fn (): bool => true, E_WARNING | E_NOTICE | E_USER_WARNING | E_USER_NOTICE | E_DEPRECATED | E_USER_DEPRECATED);

        try {
            return $work();
        } finally {
            restore_error_handler();
        }
    }
}
