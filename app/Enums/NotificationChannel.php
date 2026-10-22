<?php

namespace App\Enums;

use App\Models\WhatsAppTemplate;
use App\Services\WhatsAppCloud;

/**
 * Where a notification reaches someone. The bell is the in-app one — its pop-up, the desktop
 * notification and the phone push all follow it, as they come from the same record.
 */
enum NotificationChannel: string
{
    case BELL = 'bell';
    case MAIL = 'mail';
    case WHATSAPP = 'whatsapp';

    /**
     * Whether it reaches anyone at all: WhatsApp only once it is set up and has the template notifications
     * go out with (Notifier::whatsapp) — until then it is not offered, in the profile or the roles' grid.
     * Read once a request.
     */
    public function isAvailable(): bool
    {
        return match ($this) {
            self::WHATSAPP => once(fn (): bool => WhatsAppCloud::configured() && WhatsAppTemplate::default(WhatsAppTemplate::NOTIFICATION) !== null),
            default => true,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::BELL => __('Bell'),
            self::MAIL => __('Email'),
            self::WHATSAPP => __('WhatsApp'),
        };
    }
}
