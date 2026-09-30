<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Where a flight ticket stands — worked out from the ticket itself (paid
 * date, payroll run), never stored on its own.
 */
enum FlightTicketStatus: string implements HasColor, HasLabel
{
    case DUE = 'due';
    case IN_PAYROLL = 'in_payroll';
    case PAID = 'paid';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::DUE => __('Due'),
            self::IN_PAYROLL => __('In payroll'),
            self::PAID => __('Paid'),
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::DUE => 'warning',
            self::IN_PAYROLL => 'info',
            self::PAID => 'success',
        };
    }
}
