<?php

namespace App\Enums;

/**
 * What a file is: it decides which records may hold it, whether it may be deleted for good, and (later)
 * where it is copied in OneDrive. Stored as its value.
 */
enum AttachmentCategory: string
{
    case GENERAL = 'general';
    case REQUEST = 'request';
    case COURT_DOCUMENT = 'court_document';
    case REPORT = 'report';
    case CORRESPONDENCE = 'correspondence';
    case MINUTES_SIGNED = 'minutes_signed';
    case CONTRACT = 'contract';

    // Fees and allocations
    case FEE_INVOICE = 'fee_invoice';
    case TAX_INVOICE = 'tax_invoice';
    case PAYMENT_RECEIPT = 'payment_receipt';
    case BANK_TRANSFER = 'bank_transfer';
    case CHEQUE = 'cheque';

    public function label(): string
    {
        return match ($this) {
            self::GENERAL => __('General'),
            self::REQUEST => __('Request'),
            self::COURT_DOCUMENT => __('Court document'),
            self::REPORT => __('Report'),
            self::CORRESPONDENCE => __('Correspondence'),
            self::MINUTES_SIGNED => __('Minutes signed'),
            self::CONTRACT => __('Contract'),
            self::FEE_INVOICE => __('Fee invoice'),
            self::TAX_INVOICE => __('Tax invoice'),
            self::PAYMENT_RECEIPT => __('Payment receipt'),
            self::BANK_TRANSFER => __('Bank transfer'),
            self::CHEQUE => __('Cheque'),
        };
    }

    /**
     * Financial evidence: kept even when it is "deleted" (soft deleted, the file stays) — nothing of it is
     * lost for good.
     */
    public function keepsEvidence(): bool
    {
        return in_array($this, [self::FEE_INVOICE, self::TAX_INVOICE, self::PAYMENT_RECEIPT, self::BANK_TRANSFER, self::CHEQUE], true);
    }

    /** The category of a file made before categories, from the old free-text type. */
    public static function fromLegacyType(?string $type): self
    {
        return match ($type) {
            'matter-request' => self::REQUEST,
            'correspondence' => self::CORRESPONDENCE,
            'minutes_signed' => self::MINUTES_SIGNED,
            default => self::GENERAL,
        };
    }

    /**
     * @return array<string, string> value => label
     */
    public static function options(self ...$categories): array
    {
        return collect($categories ?: self::cases())->mapWithKeys(fn (self $category): array => [$category->value => $category->label()])->all();
    }
}
