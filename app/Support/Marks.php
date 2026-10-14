<?php

namespace App\Support;

/**
 * The check box and the radio button of a form — ticked or not — as images.
 * A text symbol (☐ ☑ ○ ●) prints as an empty box in the letters' font, so a
 * letter's marks are pictures: {{box …}} and {{radio …}} in a template (see
 * BulkMailPlaceholders::marks()).
 */
class Marks
{
    /** @var array<string, string> */
    private static array $cache = [];

    public static function box(bool $on): string
    {
        return self::image($on ? 'box-on' : 'box');
    }

    public static function radio(bool $on): string
    {
        return self::image($on ? 'radio-on' : 'radio');
    }

    private static function image(string $name): string
    {
        $data = self::$cache[$name] ??= base64_encode((string) file_get_contents(resource_path('images/marks/'.$name.'.png')));

        return '<img src="data:image/png;base64,'.$data.'" width="14" height="14" alt="">';
    }
}
