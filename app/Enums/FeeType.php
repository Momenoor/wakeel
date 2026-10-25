<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum FeeType: string implements HasColor, HasLabel
{
    case EXPERT_FEE = 'expert fee';

    case MARKETING = 'marketing';
    case VAT = 'VAT';
    case COURT_PENALITY = 'court penality';

    case OFFICE_SHARE = 'office share';
    case OTHER = 'other';

    case UNCOLLECTED = 'uncollected';

    case DISCOUNT = 'discount';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::EXPERT_FEE => __('Expert Fee'),
            self::MARKETING => __('Marketing'),
            self::VAT => __('VAT'),
            self::COURT_PENALITY => __('Court Penality'),
            self::OFFICE_SHARE => __('Office Share'),
            self::UNCOLLECTED => __('Uncollected'),
            self::DISCOUNT => __('Discount'),
            self::OTHER => __('Other'),
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::EXPERT_FEE => 'success',
            self::MARKETING => 'warning',
            self::VAT => 'info',
            self::COURT_PENALITY, self::DISCOUNT, self::OFFICE_SHARE, self::UNCOLLECTED => 'danger',
            self::OTHER => 'gray',
        };
    }

    /**
     * Whether a fee's type — the enum, or its stored value from a form's
     * state — is VAT.
     */
    public static function isVat(mixed $type): bool
    {
        return ($type instanceof self ? $type : self::tryFrom((string) $type)) === self::VAT;
    }

    /**
     * Whether VAT is charged on it (App\Support\Vat): the office's fees — not a deduction, nor the VAT rows of
     * before, which were the VAT itself.
     */
    public function carriesVat(): bool
    {
        return ! $this->isNegative() && $this !== self::VAT;
    }

    /**
     * Whether its amount is entered as the total, VAT included, until changed: an expert fee, as courts order it.
     */
    public function vatIncludedByDefault(): bool
    {
        return $this === self::EXPERT_FEE;
    }

    /**
     * The types a new fee can be: all but VAT — a fee carries its own VAT now; the VAT rows of before are kept
     * as they were.
     *
     * @return array<string, string>
     */
    public static function newFeeOptions(): array
    {
        return collect(self::cases())->reject(fn (self $type): bool => $type === self::VAT)
            ->mapWithKeys(fn (self $type): array => [$type->value => (string) $type->getLabel()])
            ->all();
    }

    /** A type from the enum, its stored value, or a form's state; null when none. */
    public static function of(mixed $type): ?self
    {
        return $type instanceof self ? $type : self::tryFrom((string) $type);
    }

    public function isNegative(): bool
    {
        return match ($this) {
            self::COURT_PENALITY, self::OFFICE_SHARE, self::MARKETING, self::UNCOLLECTED, self::DISCOUNT => true,
            default => false,
        };
    }

    /**
     * Deduction-type fees (court penalty, office share, marketing, uncollected,
     * discount) reduce a matter's net revenue but must never become their own
     * incentive line — they're netted against the matter's revenue fee(s) instead.
     *
     * @return list<string>
     */
    public static function deductionTypeValues(): array
    {
        return collect(self::cases())->filter(fn (self $c) => $c->isNegative())->map(fn (self $c) => $c->value)->all();
    }

    /**
     * Fee types that should never be picked as an incentive-eligible fee:
     * VAT (a pass-through tax, not office revenue) plus every deduction type.
     *
     * @return list<string>
     */
    public static function excludedFromIncentiveValues(): array
    {
        return [...self::deductionTypeValues(), self::VAT->value];
    }
}
