<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A reusable covering email for sending letters — subject and body with
 * the same {{placeholders}} as letters ({{reference}}, {{subject}},
 * {{matter.*}}, {{recipient.name}} …).
 */
#[Fillable('name', 'locale', 'subject', 'body', 'is_active', 'is_default')]
class EmailTemplate extends Model
{
    public function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_default' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // Only one default.
        static::saved(function (EmailTemplate $template): void {
            if ($template->is_default) {
                static::query()->whereKeyNot($template->getKey())->where('is_default', true)->update(['is_default' => false]);
            }
        });
    }

    public static function default(): ?self
    {
        return static::query()->where('is_active', true)->orderByDesc('is_default')->oldest('id')->first();
    }
}
