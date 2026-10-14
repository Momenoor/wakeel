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
    public static function make(string $name = 'iban'): TextInput
    {
        return TextInput::make($name)
            ->label(__('IBAN'))
            ->maxLength(34)
            ->rule('regex:/^AE\d{21}$/')
            ->validationMessages([
                'regex' => __('A UAE IBAN is AE followed by 21 digits.'),
            ])
            ->placeholder('AE070331234567890123456');
    }
}
