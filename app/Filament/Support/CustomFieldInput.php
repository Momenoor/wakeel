<?php

namespace App\Filament\Support;

use App\Models\MatterFieldDefinition;
use Closure;
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
    /**
     * The inputs for a matter type's custom fields, drawn after the type is chosen. Each field
     * gets its place in the form's data first (`custom_fields.<label>`, empty): the form was
     * filled before these fields existed, and the browser cannot send a choice to a place that is
     * not there — Livewire says "property … cannot be found" and the value never reaches the server,
     * so a required field showing a choice is reported as empty.
     *
     * @param  iterable<MatterFieldDefinition>  $fields
     * @param  array<string, mixed>  $current  the form's `custom_fields` as it is
     * @param  Closure(array<string, mixed>): void  $setSlots  puts the whole `custom_fields` back into the form
     * @return list<Select|DatePicker|Toggle|TextInput>
     */
    public static function components(iterable $fields, array $current, Closure $setSlots): array
    {
        $fields = collect($fields);
        $missing = $fields->reject(fn (MatterFieldDefinition $field): bool => array_key_exists($field->label, $current));

        if ($missing->isNotEmpty()) {
            $setSlots([...$current, ...$missing->mapWithKeys(fn (MatterFieldDefinition $field): array => [$field->label => null])->all()]);
        }

        return $fields->map(fn (MatterFieldDefinition $field) => self::make($field))->values()->all();
    }

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
