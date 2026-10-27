<?php

namespace App\Enums;

use App\Models\Setting;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * The leave types a request period can carry.
 *
 * Each case owns its own pay factor, so payroll multiplies days by a number and
 * never branches on the type itself. Adding a type later — compassionate leave,
 * study leave — means adding a case and its factor here, and nothing in the
 * payroll engine changes.
 */
enum LeaveType: string implements HasColor, HasLabel
{
    case ANNUAL = 'annual';
    case CASUAL = 'casual';

    /**
     * Article 31 pays sick leave in three bands per year of service:
     * 15 days full, the next 30 at half, the following 45 unpaid.
     */
    case SICK_FULL = 'sick_full';
    case SICK_HALF = 'sick_half';
    case SICK_UNPAID = 'sick_unpaid';

    case UNPAID = 'unpaid';
    case ABSENT = 'absent';

    /**
     * Granted for an event: a new baby, a family member's death. Paid in full; taken off the annual balance like
     * casual leave unless Settings → Payroll says otherwise, HR adding the days to it by hand (an adjustment).
     */
    case NEW_BABY = 'new_baby';
    case BEREAVEMENT = 'bereavement';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::ANNUAL => __('Annual Leave'),
            self::CASUAL => __('Casual Leave'),
            self::SICK_FULL => __('Sick Leave (Full Pay)'),
            self::SICK_HALF => __('Sick Leave (Half Pay)'),
            self::SICK_UNPAID => __('Sick Leave (Unpaid)'),
            self::UNPAID => __('Unpaid Leave'),
            self::ABSENT => __('Unauthorised Absence'),
            self::NEW_BABY => __('New Baby Leave'),
            self::BEREAVEMENT => __('Bereavement Leave'),
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::ANNUAL => 'success',
            self::CASUAL, self::NEW_BABY, self::BEREAVEMENT => 'info',
            self::SICK_FULL, self::SICK_HALF => 'warning',
            self::SICK_UNPAID, self::UNPAID, self::ABSENT => 'danger',
        };
    }

    /**
     * The share of a normal day's pay this type earns.
     */
    public function payFactor(): float
    {
        return match ($this) {
            self::ANNUAL, self::CASUAL, self::SICK_FULL, self::NEW_BABY, self::BEREAVEMENT => 1.0,
            self::SICK_HALF => 0.5,
            self::SICK_UNPAID, self::UNPAID, self::ABSENT => 0.0,
        };
    }

    /**
     * Whether a day of this type counts toward end-of-service length of service.
     *
     * Article 51 excludes unpaid leave from the service period, so a month with
     * unpaid days accrues proportionally less gratuity.
     */
    public function accruesService(): bool
    {
        return $this->payFactor() > 0.0;
    }

    /** The setting naming the types besides annual leave taken off the balance. */
    public const FROM_BALANCE_SETTING = 'payroll_leave_types_from_balance';

    /**
     * The types that may be taken off the balance besides annual leave, which always is: the paid ones granted
     * for a reason, their days given by hand (Adjust balance).
     *
     * @return list<self>
     */
    public static function optionallyFromBalance(): array
    {
        return [self::CASUAL, self::NEW_BABY, self::BEREAVEMENT];
    }

    /**
     * Types that draw down the annual balance: annual leave, and those Settings → Payroll names (all of
     * optionallyFromBalance() until it is saved).
     *
     * @return list<self>
     */
    public static function deductingFromAnnualBalance(): array
    {
        $chosen = (array) Setting::get(self::FROM_BALANCE_SETTING, array_map(fn (self $type): string => $type->value, self::optionallyFromBalance()));

        return [self::ANNUAL, ...array_values(array_filter(self::optionallyFromBalance(), fn (self $type): bool => in_array($type->value, $chosen, true)))];
    }

    public function takesFromBalance(): bool
    {
        return in_array($this, self::deductingFromAnnualBalance(), true);
    }

    /** A type, or its value as a form holds it, taken off the balance. */
    public static function fromBalance(self|string|null $type): bool
    {
        $type = $type instanceof self ? $type : self::tryFrom((string) $type);

        return $type?->takesFromBalance() ?? false;
    }
}
