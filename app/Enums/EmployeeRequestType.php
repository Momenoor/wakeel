<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

/**
 * What an employee asks for (EmployeeRequest): money — a loan, petty cash or part of their salary in advance,
 * paid back from their salary; or their year's flight ticket early, which payroll then does not pay again —
 * or a certificate issued from a template (a salary certificate, a no-objection certificate …).
 */
enum EmployeeRequestType: string implements HasColor, HasIcon, HasLabel
{
    case LOAN = 'loan';
    case PETTY_CASH = 'petty_cash';
    case SALARY_ADVANCE = 'salary_advance';
    case TICKET_ADVANCE = 'ticket_advance';
    case CERTIFICATE = 'certificate';

    public function getLabel(): string
    {
        return match ($this) {
            self::LOAN => __('Loan'),
            self::PETTY_CASH => __('Petty cash'),
            self::SALARY_ADVANCE => __('Salary advance'),
            self::TICKET_ADVANCE => __('Ticket advance'),
            self::CERTIFICATE => __('Certificate'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::LOAN => 'warning',
            self::PETTY_CASH => 'info',
            self::SALARY_ADVANCE => 'primary',
            self::TICKET_ADVANCE => 'gray',
            self::CERTIFICATE => 'success',
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::LOAN => Heroicon::OutlinedBanknotes,
            self::PETTY_CASH => Heroicon::OutlinedWallet,
            self::SALARY_ADVANCE => Heroicon::OutlinedCalendarDays,
            self::TICKET_ADVANCE => Heroicon::OutlinedPaperAirplane,
            self::CERTIFICATE => Heroicon::OutlinedDocumentCheck,
        };
    }

    /** The advance it becomes once paid, recovered from the salary; none for a ticket or a certificate. */
    public function loanKind(): ?LoanKind
    {
        return match ($this) {
            self::LOAN => LoanKind::LOAN,
            self::PETTY_CASH => LoanKind::PETTY_CASH,
            self::SALARY_ADVANCE => LoanKind::SALARY_ADVANCE,
            self::TICKET_ADVANCE, self::CERTIFICATE => null,
        };
    }

    /** Money the accountant pays: everything but a certificate. */
    public function isMoney(): bool
    {
        return $this !== self::CERTIFICATE;
    }

    /** Paid back from the salary — over months, from a month. */
    public function isRepaid(): bool
    {
        return $this->loanKind() !== null;
    }

    /** @return list<string> */
    public static function moneyValues(): array
    {
        return collect(self::cases())->filter(fn (self $type): bool => $type->isMoney())->map(fn (self $type): string => $type->value)->values()->all();
    }
}
