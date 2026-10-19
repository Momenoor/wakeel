<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

/**
 * Where a calendar event was made: in the system, or outside it — in Outlook, Teams or a phone's calendar, all
 * the same Outlook calendar, brought in by its sync (CalendarEvent::imported_from_outlook). Which of those three
 * made it, Outlook does not say.
 */
enum CalendarEventSource: string implements HasColor, HasIcon, HasLabel
{
    case SYSTEM = 'system';
    case OUTSIDE = 'outside';

    public function getLabel(): string
    {
        return match ($this) {
            self::SYSTEM => __('Created in Wakeel'),
            self::OUTSIDE => __('From Outlook, Teams or a phone'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::SYSTEM => 'primary',
            self::OUTSIDE => 'gray',
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::SYSTEM => Heroicon::OutlinedComputerDesktop,
            self::OUTSIDE => Heroicon::OutlinedDevicePhoneMobile,
        };
    }

    /** Its colour in the calendar (FullCalendar takes a CSS colour); null: the calendar's own. */
    public function calendarColor(): ?string
    {
        return match ($this) {
            self::SYSTEM => null,
            self::OUTSIDE => '#6b7280',
        };
    }
}
