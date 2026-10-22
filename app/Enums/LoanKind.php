<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Advances recovered from salary.
 *
 * All settle against the employee the same way; they differ only in which
 * asset account the journal voucher clears.
 */
enum LoanKind: string implements HasColor, HasLabel
{
    case LOAN = 'loan';
    case PETTY_CASH = 'petty_cash';
    case SALARY_ADVANCE = 'salary_advance';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::LOAN => __('Staff Loan'),
            self::PETTY_CASH => __('Petty Cash'),
            self::SALARY_ADVANCE => __('Salary advance'),
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::LOAN => 'warning',
            self::PETTY_CASH => 'info',
            self::SALARY_ADVANCE => 'primary',
        };
    }

    public function glAccount(): string
    {
        return match ($this) {
            self::LOAN => 'Staff Loans Receivable',
            self::PETTY_CASH => 'Petty Cash Advances',
            self::SALARY_ADVANCE => 'Salary Advances',
        };
    }
}
