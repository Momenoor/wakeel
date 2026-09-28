<?php

namespace App\Support;

use App\Enums\PMS\InstallmentPaymentMethod;

/**
 * Cheque numbers as printed on the cheque: six digits, zero-padded —
 * "36" is recorded as "000036". Only a plain number is padded; anything
 * else (letters, a longer number) is kept as typed.
 */
final class ChequeNumber
{
    public const DIGITS = 6;

    public static function format(?string $reference): ?string
    {
        $reference = $reference === null ? null : trim($reference);

        if ($reference === null || $reference === '') {
            return null;
        }

        return ctype_digit($reference) ? str_pad($reference, self::DIGITS, '0', STR_PAD_LEFT) : $reference;
    }

    /**
     * Formatted when the payment method is a cheque, as typed otherwise.
     */
    public static function forMethod(?string $reference, mixed $method): ?string
    {
        $method = $method instanceof InstallmentPaymentMethod ? $method->value : $method;

        if ($method !== InstallmentPaymentMethod::POST_DATED_CHEQUE->value) {
            return filled($reference) ? trim((string) $reference) : null;
        }

        return self::format($reference);
    }
}
