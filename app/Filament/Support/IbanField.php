<?php

namespace App\Filament\Support;

use Filament\Forms\Components\TextInput;

/**
 * The IBAN input, with the UAE rule everywhere a bank account is typed. A UAE
 * IBAN is AE plus 21 digits, always; catching a mistyped one here is the
 * difference between a rejected transfer and a payment to a stranger.
 */
class IbanField
{
    /**
     * @param  bool  $uaeOnly  false for an account that may be abroad (a creditor's): any country's IBAN — two
     *                         letters, two digits, then up to 30 letters or digits — spaces allowed, kept without
     */
    public static function make(string $name = 'iban', bool $uaeOnly = true): TextInput
    {
        return TextInput::make($name)
            ->label(__('IBAN'))
            ->maxLength($uaeOnly ? 34 : 42)
            ->rule($uaeOnly ? 'regex:/^AE\d{21}$/' : 'regex:/^\s*[A-Za-z]{2}\s*\d{2}(\s*[A-Za-z0-9]){11,30}\s*$/')
            ->validationMessages([
                'regex' => $uaeOnly ? __('A UAE IBAN is AE followed by 21 digits.') : __('An IBAN is two letters, two digits, then up to 30 letters or digits.'),
            ])
            ->placeholder('AE070331234567890123456');
    }
}
