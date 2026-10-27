<?php

namespace App\Support;

/**
 * What every notification email's components (resources/views/components/mail) write on each element, since
 * Outlook for Windows reads only what is on the element itself: the direction on every table (it lays a table's
 * columns out left to right unless the table says otherwise, whatever its parent says), the side text starts on,
 * and a font Outlook has when it cannot load the web font.
 */
class MailStyle
{
    public const FONT = "'Boutros MBC Dinkum', Tahoma, Arial, sans-serif";

    public static function rtl(): bool
    {
        return app()->getLocale() === 'ar';
    }

    public static function dir(): string
    {
        return self::rtl() ? 'rtl' : 'ltr';
    }

    /** The side the text starts on: right in Arabic. */
    public static function start(): string
    {
        return self::rtl() ? 'right' : 'left';
    }
}
