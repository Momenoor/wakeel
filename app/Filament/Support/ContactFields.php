<?php

namespace App\Filament\Support;

use Filament\Forms\Components\TagsInput;

/**
 * The phone and email fields every party-like form (parties, tenants, owners,
 * owner groups) shows: several values, separated by space, comma or Enter.
 */
class ContactFields
{
    public static function phone(): TagsInput
    {
        return TagsInput::make('phone')
            ->label(__('Phone'))
            ->trim()
            ->splitKeys(['Tab', ' ', ',', 'Enter']);
    }

    public static function email(): TagsInput
    {
        return TagsInput::make('email')
            ->label(__('Email'))
            ->trim()
            ->splitKeys(['Tab', ' ', ',', 'Enter'])
            ->nestedRecursiveRules(['email']);
    }
}
