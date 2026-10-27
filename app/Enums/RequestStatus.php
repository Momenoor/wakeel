<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum RequestStatus: string implements HasColor, HasLabel
{
    case APPROVED = 'approved';
    case REJECTED = 'rejected';
    case PENDING = 'pending';

    case DISPUTED = 'disputed';

    /** A reviewer asked for changes to the report: it goes back to the assistant, then to review again. */
    case NEEDS_CHANGES = 'needs_changes';

    /** An approved leave called off before it was taken: its days given back. */
    case CANCELLED = 'cancelled';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::APPROVED => __('Approved'),
            self::REJECTED => __('Rejected'),
            self::PENDING => __('Pending'),
            self::DISPUTED => __('Disputed'),
            self::NEEDS_CHANGES => __('Needs changes'),
            self::CANCELLED => __('Cancelled'),
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::APPROVED => 'success',
            self::REJECTED => 'danger',
            self::PENDING => 'warning',
            self::DISPUTED => 'info',
            self::NEEDS_CHANGES => 'primary',
            self::CANCELLED => 'gray',
        };
    }
}
