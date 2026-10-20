<?php

namespace App\Services\MMS;

use RuntimeException;

/**
 * Microsoft 365 has no OneDrive, or no such item, where it was looked for — an account that never opened its
 * OneDrive, a folder that is not in it. Something to tell the user, not a fault to log; still a
 * RuntimeException to whoever catches those.
 */
class OneDriveNotFound extends RuntimeException
{
    /** @param  ?string  $graphCode  Microsoft Graph's own word for it: "itemNotFound" for a missing item */
    public function __construct(string $message, public readonly ?string $graphCode = null)
    {
        parent::__construct($message);
    }

    /** A file or folder missing — the OneDrive itself is there. */
    public function isItem(): bool
    {
        return $this->graphCode === 'itemNotFound';
    }
}
