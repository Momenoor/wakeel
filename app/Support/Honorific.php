<?php

namespace App\Support;

/**
 * How someone is addressed: the title before the name and, in Arabic, the
 * honorific after it that agrees with it — السيد/ and الأستاذ/ … المحترم,
 * السيدة/ and الأستاذة/ … المحترمة, السادة/ … المحترمين.
 *
 * Names often reach a message with their title already on them (an
 * attendee "الأستاذ/ موزة …"); a template adding its own "السادة/ … المحترمين"
 * then read "السادة/ الأستاذ/ موزة … المحترمين". split() takes the title
 * off, so {{recipient.name}} is the bare name and {{recipient.salutation}}
 * the whole, agreeing greeting.
 */
class Honorific
{
    /** Arabic titles and the honorific each takes. */
    public const ARABIC = [
        'السادة/' => 'المحترمين',
        'الأستاذ/' => 'المحترم',
        'السيد/' => 'المحترم',
        'الأستاذة/' => 'المحترمة',
        'السيدة/' => 'المحترمة',
    ];

    public const ENGLISH = ['Messrs.', 'Mrs.', 'Mr.', 'Ms.', 'Dr.'];

    /**
     * The title (the innermost, if one was written before another) and the
     * bare name.
     *
     * @return array{title: ?string, name: string}
     */
    public static function split(string $full): array
    {
        $name = trim(preg_replace('/\s+/u', ' ', $full) ?? '');
        $title = null;

        do {
            $found = false;

            foreach ([...array_keys(self::ARABIC), ...self::ENGLISH] as $candidate) {
                $plain = rtrim($candidate, '/.');
                // "الأستاذ/", "الأستاذ /", "الاستاذ/" and "Mr" / "Mr." alike.
                $letters = preg_split('//u', $plain, -1, PREG_SPLIT_NO_EMPTY) ?: [];
                // An Arabic title only with its slash: "السيد" may be a name.
                $mark = str_ends_with($candidate, '/') ? '\s*\/' : '\.?';
                $pattern = '/^'.implode('', array_map(fn (string $c): string => in_array($c, ['أ', 'ا', 'إ'], true) ? '[أاإ]' : preg_quote($c, '/'), $letters)).$mark.'\s+/u';

                if (preg_match($pattern, $name.' ') === 1 && $name !== $plain) {
                    $name = trim((string) preg_replace($pattern, '', $name.' '));
                    $title = $candidate;
                    $found = true;

                    break;
                }
            }
        } while ($found && $name !== '');

        return ['title' => $title, 'name' => $name];
    }

    /**
     * The honorific after the name: the title's, else the plural (السادة).
     */
    public static function suffix(?string $title, bool $arabic = true): string
    {
        if (! $arabic) {
            return '';
        }

        return self::ARABIC[$title] ?? self::ARABIC['السادة/'];
    }

    /**
     * "الأستاذة/ موزة هاشل خلف الغيث المحترمة" — without a title, the plural
     * "السادة/ … المحترمين", polite for anyone. English: "Mr. John Smith".
     */
    public static function salutation(string $full, bool $arabic = true): string
    {
        ['title' => $title, 'name' => $name] = self::split($full);

        if (! $arabic) {
            return trim(($title ?? '').' '.$name);
        }

        $title = isset(self::ARABIC[$title ?? '']) ? $title : 'السادة/';

        return trim($title.' '.$name.' '.self::suffix($title));
    }

    /**
     * The recipient's placeholders from how they are named.
     *
     * @return array{'recipient.name': string, 'recipient.title': string, 'recipient.suffix': string, 'recipient.salutation': string}
     */
    public static function values(string $full, bool $arabic = true): array
    {
        ['title' => $title, 'name' => $name] = self::split($full);
        $title = $arabic && ! isset(self::ARABIC[$title ?? '']) ? 'السادة/' : (string) $title;

        return [
            'recipient.name' => $name,
            'recipient.title' => $title,
            'recipient.suffix' => self::suffix($title, $arabic),
            'recipient.salutation' => self::salutation($full, $arabic),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function catalog(): array
    {
        return [
            'recipient.salutation' => __('Recipient — title, name and honorific (الأستاذة/ … المحترمة)'),
            'recipient.title' => __('Recipient — title (السادة/، الأستاذ/ …)'),
            'recipient.suffix' => __('Recipient — honorific (المحترم، المحترمة، المحترمين)'),
        ];
    }
}
