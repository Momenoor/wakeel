<?php

namespace App\Enums;

/**
 * What a library image is: a handwritten signature or a stamp. It decides the size it is first put on a page
 * at; the editor treats both the same otherwise. Stored as its value.
 */
enum SignatureStampKind: string
{
    case SIGNATURE = 'signature';
    case STAMP = 'stamp';

    public function label(): string
    {
        return match ($this) {
            self::SIGNATURE => __('Signature'),
            self::STAMP => __('Stamp'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::SIGNATURE => 'info',
            self::STAMP => 'warning',
        };
    }

    /** How wide a new one is put on a page, in millimetres — a signature line is wider than a round stamp. */
    public function defaultWidthMm(): float
    {
        return match ($this) {
            self::SIGNATURE => 40.0,
            self::STAMP => 35.0,
        };
    }

    /**
     * @return array<string, string> value => label
     */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $kind): array => [$kind->value => $kind->label()])->all();
    }
}
