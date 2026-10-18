<?php

namespace App\Enums;

/**
 * Where a notification reaches someone. The bell is the in-app one — its pop-up, the desktop
 * notification and the phone push all follow it, as they come from the same record.
 */
enum NotificationChannel: string
{
    case BELL = 'bell';
    case MAIL = 'mail';
    case WHATSAPP = 'whatsapp';

    public function label(): string
    {
        return match ($this) {
            self::BELL => __('Bell'),
            self::MAIL => __('Email'),
            self::WHATSAPP => __('WhatsApp'),
        };
    }
}
