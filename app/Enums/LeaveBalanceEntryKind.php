<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * What moved an employee's annual-leave balance.
 */
enum LeaveBalanceEntryKind: string implements HasColor, HasLabel
{
    /** Days carried over from before the balance was tracked here. */
    case OPENING = 'opening';

    /** The yearly grant, every 1 January. */
    case ANNUAL_GRANT = 'annual_grant';

    /** A new joiner's grant for the rest of their joining year. */
    case JOINING_GRANT = 'joining_grant';

    /** Annual leave taken, written when a request is approved. */
    case TAKEN = 'taken';

    /** A correction by HR, with a reason. */
    case ADJUSTMENT = 'adjustment';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::OPENING => __('Opening balance'),
            self::ANNUAL_GRANT => __('Annual grant'),
            self::JOINING_GRANT => __('Joining grant'),
            self::TAKEN => __('Leave taken'),
            self::ADJUSTMENT => __('Adjustment'),
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::OPENING => 'gray',
            self::ANNUAL_GRANT, self::JOINING_GRANT => 'success',
            self::TAKEN => 'warning',
            self::ADJUSTMENT => 'info',
        };
    }
}
