<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Contracts\Support\Htmlable;

enum LetterTemplateCategories: string implements HasColor, HasLabel
{
    case LETTER = 'letter';
    case COURT_NOTICES = 'court_notices';
    case MEETING_NOTICES = 'meeting_notices';
    case APPOINTMENTS = 'appointments';
    case REPORTS = 'reports';
    case MINUTES = 'minutes';
    case CERTIFICATES = 'certificates';
    case OTHERS = 'others';

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::LETTER => 'success',
            self::COURT_NOTICES => 'warning',
            self::MEETING_NOTICES => 'info',
            self::APPOINTMENTS => 'primary',
            self::REPORTS => 'danger',
            self::MINUTES => 'info',
            self::CERTIFICATES => 'success',
            self::OTHERS => 'gray',
        };
    }

    public function getLabel(): string|Htmlable|null
    {
        return match ($this) {
            self::LETTER => __('Letter'),
            self::COURT_NOTICES => __('Court Notices'),
            self::MEETING_NOTICES => __('Meeting Notices'),
            self::APPOINTMENTS => __('Appointments'),
            self::REPORTS => __('Reports'),
            self::MINUTES => __('Meeting minutes'),
            self::CERTIFICATES => __('Employee certificates'),
            self::OTHERS => __('Others'),
        };
    }

    /** Not issued as a matter's letter: minutes are made from a meeting, certificates for an employee. */
    public static function notLetters(): array
    {
        return [self::MINUTES->value, self::CERTIFICATES->value];
    }
}
