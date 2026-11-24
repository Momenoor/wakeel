<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * What became of a sent email at an address, as Microsoft 365's message trace says (MessageTrace) — and, for an
 * email, the worst of its addresses.
 */
enum DeliveryStatus: string implements HasColor, HasIcon, HasLabel
{
    case DELIVERED = 'delivered';

    /** Sent to a group, which Microsoft 365 handed on to its members. */
    case EXPANDED = 'expanded';

    /** Still on its way, or Microsoft 365 not yet sure. */
    case PENDING = 'pending';

    case FAILED = 'failed';

    case QUARANTINED = 'quarantined';

    case SPAM = 'spam';

    /** Called back by its sender in Outlook. */
    case RECALLED = 'recalled';

    /** Microsoft 365 has no trace of it: not sent through it, or too long ago. */
    case NONE = 'none';

    /** Microsoft Graph's name for it (exchangeMessageTraceStatus): null for one it does not know. */
    public static function fromTrace(?string $status): ?self
    {
        return match (strtolower((string) $status)) {
            'delivered' => self::DELIVERED,
            'expanded' => self::EXPANDED,
            'pending', 'gettingstatus' => self::PENDING,
            'failed' => self::FAILED,
            'quarantined' => self::QUARANTINED,
            'filteredasspam' => self::SPAM,
            'recalled' => self::RECALLED,
            default => null,
        };
    }

    /** Nothing more will come of it: not looked for again. */
    public function isFinal(): bool
    {
        return $this !== self::PENDING;
    }

    /** It did not reach the address. */
    public function isProblem(): bool
    {
        return in_array($this, [self::FAILED, self::QUARANTINED, self::SPAM], true);
    }

    /** How bad it is, for an email's worst address: the higher, the worse. */
    public function weight(): int
    {
        return match ($this) {
            self::FAILED => 7,
            self::QUARANTINED => 6,
            self::SPAM => 5,
            self::RECALLED => 4,
            self::PENDING => 3,
            self::NONE => 2,
            self::EXPANDED => 1,
            self::DELIVERED => 0,
        };
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::DELIVERED => __('Delivered'),
            self::EXPANDED => __('Sent to a group'),
            self::PENDING => __('On its way'),
            self::FAILED => __('Not delivered'),
            self::QUARANTINED => __('Quarantined'),
            self::SPAM => __('Taken for spam'),
            self::RECALLED => __('Recalled'),
            self::NONE => __('No trace'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::DELIVERED, self::EXPANDED => 'success',
            self::PENDING => 'warning',
            self::FAILED, self::QUARANTINED, self::SPAM => 'danger',
            self::RECALLED, self::NONE => 'gray',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::DELIVERED, self::EXPANDED => 'heroicon-o-check-circle',
            self::PENDING => 'heroicon-o-clock',
            self::FAILED, self::QUARANTINED, self::SPAM => 'heroicon-o-exclamation-triangle',
            self::RECALLED => 'heroicon-o-arrow-uturn-left',
            self::NONE => 'heroicon-o-minus-circle',
        };
    }
}
