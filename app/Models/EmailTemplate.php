<?php

namespace App\Models;

use App\Support\Addresses;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A reusable email — subject and body with the same {{placeholders}} as
 * letters ({{reference}}, {{subject}}, {{matter.*}}, {{recipient.name}} …):
 * a letter's covering email, or the email sending minutes for signature.
 */
#[Fillable('name', 'purpose', 'locale', 'subject', 'body', 'cc', 'is_active', 'is_default')]
class EmailTemplate extends Model
{
    /** The email a letter is attached to (or sent as). */
    public const LETTER = 'letter';

    /** Minutes sent to the attendees to sign and send back. */
    public const MINUTES_SIGNATURE = 'minutes_signature';

    protected $attributes = [
        'purpose' => self::LETTER,
    ];

    public function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_default' => 'boolean',
            'cc' => 'array',
        ];
    }

    /**
     * A template's own CC list.
     *
     * @return list<string>
     */
    public static function ccOf(mixed $id): array
    {
        return filled($id) ? Addresses::emails((array) (static::query()->whereKey($id)->value('cc') ?? [])) : [];
    }

    /**
     * Another template chosen: the CC list without the one before's, with
     * the new one's — whatever was typed or kept stays.
     *
     * @param  list<string>  $cc
     * @return list<string>
     */
    public static function swapCc(array $cc, mixed $before, mixed $now): array
    {
        $cc = Addresses::without(Addresses::emails($cc), self::ccOf($before));

        return Addresses::emails([...$cc, ...self::ccOf($now)]);
    }

    protected static function booted(): void
    {
        // One default for each purpose.
        static::saved(function (EmailTemplate $template): void {
            if ($template->is_default) {
                static::query()->whereKeyNot($template->getKey())->where('purpose', $template->purpose)->where('is_default', true)->update(['is_default' => false]);
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public static function purposes(): array
    {
        return [
            self::LETTER => __('Letters (covering email)'),
            self::MINUTES_SIGNATURE => __('Minutes for signature'),
        ];
    }

    /**
     * The one to start from: in the given language when there is one, the
     * default before the rest.
     */
    public static function default(string $purpose = self::LETTER, ?string $locale = null): ?self
    {
        return static::query()
            ->where('purpose', $purpose)
            ->where('is_active', true)
            ->when($locale, fn ($query) => $query->orderByRaw('CASE WHEN locale = ? THEN 0 ELSE 1 END', [$locale]))
            ->orderByDesc('is_default')
            ->oldest('id')
            ->first();
    }

    /**
     * @return array<int|string, string>
     */
    public static function options(string $purpose): array
    {
        return static::query()->where('purpose', $purpose)->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all();
    }
}
