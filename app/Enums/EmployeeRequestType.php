<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

/**
 * What an employee asks for (EmployeeRequest): money — a loan or a petty cash advance, paid back from their
 * salary — or a certificate issued from a template (a salary certificate, a no-objection certificate …).
 */
enum EmployeeRequestType: string implements HasColor, HasIcon, HasLabel
{
    case LOAN = 'loan';
    case PETTY_CASH = 'petty_cash';
    case CERTIFICATE = 'certificate';

    public function getLabel(): string
    {
        return match ($this) {
            self::LOAN => __('Loan'),
            self::PETTY_CASH => __('Petty cash'),
            self::CERTIFICATE => __('Certificate'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::LOAN => 'warning',
            self::PETTY_CASH => 'info',
            self::CERTIFICATE => 'success',
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::LOAN => Heroicon::OutlinedBanknotes,
            self::PETTY_CASH => Heroicon::OutlinedWallet,
            self::CERTIFICATE => Heroicon::OutlinedDocumentCheck,
        };
    }

    /** The advance it becomes once paid; none for a certificate. */
    public function loanKind(): ?LoanKind
    {
        return match ($this) {
            self::LOAN => LoanKind::LOAN,
            self::PETTY_CASH => LoanKind::PETTY_CASH,
            self::CERTIFICATE => null,
        };
    }

    public function isMoney(): bool
    {
        return $this->loanKind() !== null;
    }
}
