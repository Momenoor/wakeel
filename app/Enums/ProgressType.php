<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * A step in a matter's progress. Some are recorded as they happen (a
 * letter issued or sent, a meeting's minutes, an email to the parties, the
 * reports' dates); the rest — and any of these — are added by hand.
 */
enum ProgressType: string implements HasColor, HasIcon, HasLabel
{
    case LETTER_ISSUED = 'letter_issued';
    case LETTER_SENT = 'letter_sent';
    case MEETING_HELD = 'meeting_held';
    case MINUTES_SENT = 'minutes_sent';
    case EMAIL_SENT = 'email_sent';
    case REPLY_RECEIVED = 'reply_received';
    case INITIAL_REPORT = 'initial_report';
    case FINAL_REPORT = 'final_report';
    case DOCUMENTS_RECEIVED = 'documents_received';
    case DOCUMENTS_SUBMITTED = 'documents_submitted';
    case SESSION = 'session';
    case OTHER = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::LETTER_ISSUED => __('Letter issued'),
            self::LETTER_SENT => __('Letter sent'),
            self::MEETING_HELD => __('Meeting held'),
            self::MINUTES_SENT => __('Minutes sent'),
            self::EMAIL_SENT => __('Email sent'),
            self::REPLY_RECEIVED => __('Reply received'),
            self::INITIAL_REPORT => __('Initial report submitted'),
            self::FINAL_REPORT => __('Final report issued'),
            self::DOCUMENTS_RECEIVED => __('Documents received'),
            self::DOCUMENTS_SUBMITTED => __('Documents submitted'),
            self::SESSION => __('Session'),
            self::OTHER => __('Other'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::LETTER_ISSUED, self::LETTER_SENT => 'info',
            self::MEETING_HELD, self::MINUTES_SENT => 'primary',
            self::EMAIL_SENT => 'gray',
            self::REPLY_RECEIVED => 'success',
            self::INITIAL_REPORT => 'warning',
            self::FINAL_REPORT => 'success',
            self::DOCUMENTS_RECEIVED, self::DOCUMENTS_SUBMITTED => 'danger',
            self::SESSION, self::OTHER => 'gray',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::LETTER_ISSUED => 'heroicon-o-document-text',
            self::LETTER_SENT => 'heroicon-o-envelope',
            self::MEETING_HELD => 'heroicon-o-user-group',
            self::MINUTES_SENT => 'heroicon-o-paper-airplane',
            self::EMAIL_SENT => 'heroicon-o-at-symbol',
            self::REPLY_RECEIVED => 'heroicon-o-envelope-open',
            self::INITIAL_REPORT => 'heroicon-o-document-check',
            self::FINAL_REPORT => 'heroicon-o-check-badge',
            self::DOCUMENTS_RECEIVED => 'heroicon-o-inbox-arrow-down',
            self::DOCUMENTS_SUBMITTED => 'heroicon-o-arrow-up-tray',
            self::SESSION => 'heroicon-o-scale',
            self::OTHER => 'heroicon-o-flag',
        };
    }
}
