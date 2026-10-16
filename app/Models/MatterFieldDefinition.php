<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class MatterFieldDefinition extends Model
{
    protected $fillable = [
        'label',
        'type',
        'required',
        'show_in_supplementary',
        'options',
    ];

    protected $casts = [
        'required' => 'boolean',
        'show_in_supplementary' => 'boolean',
        'options' => 'json',
    ];

    /**
     * A dropdown's saved value as the key of the option it means. A value saved as the
     * option's label (before the options had keys of their own, or keys that were changed
     * since) is read as that option; one that matches none is left as it is.
     */
    public function optionKey(mixed $value): mixed
    {
        if ($this->type !== 'select_input' || ! is_scalar($value) || trim((string) $value) === '') {
            return $value;
        }

        $options = (array) $this->options;

        if (array_key_exists($value, $options)) {
            return $value;
        }

        $key = array_search(trim((string) $value), array_map(fn ($label): string => trim((string) $label), $options), true);

        return $key === false ? $value : (string) $key;
    }

    /**
     * What a saved value is called: a dropdown's option label (not its key); anything else
     * as it is.
     */
    public function displayValue(mixed $value): mixed
    {
        if ($this->type !== 'select_input' || ! is_scalar($value)) {
            return $value;
        }

        $key = $this->optionKey($value);
        $label = ((array) $this->options)[$key] ?? null;

        return is_scalar($label) && trim((string) $label) !== '' ? trim((string) $label) : $value;
    }

    public function types(): BelongsToMany
    {
        return $this->belongsToMany(Type::class, 'matter_field_definition_type');
    }
}
