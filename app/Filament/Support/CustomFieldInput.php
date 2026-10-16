<?php

namespace App\Filament\Support;

use App\Models\MatterFieldDefinition;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;

/**
 * The input for a matter's custom field, by the type set on the matter type —
 * the same wherever the field is filled: the matter form, the supplementary
 * matter form.
 */
class CustomFieldInput
{
    public static function make(MatterFieldDefinition $field, ?string $statePath = null): Select|DatePicker|Toggle|TextInput
    {
        $statePath ??= 'custom_fields.'.$field->label;

        $component = match ($field->type) {
            // A value saved as the option's label is read as the option, so it is not asked for again.
            'select_input' => Select::make($statePath)
                ->options($field->options ?? [])
                ->afterStateHydrated(function (Select $component, mixed $state) use ($field): void {
                    $key = $field->optionKey($state);

                    if ($key !== $state) {
                        $component->state($key);
                    }
                }),
            'date_input' => DatePicker::make($statePath),
            'toggle_input' => Toggle::make($statePath),
            default => TextInput::make($statePath),
        };

        $component->label($field->label);

        if ($field->required) {
            $component->required();
        }

        return $component;
    }
}
