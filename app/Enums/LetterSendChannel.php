<?php

namespace App\Enums;

/** A way a letter is asked to be sent by (LetterSendRequest). */
enum LetterSendChannel: string
{
    case WHATSAPP = 'whatsapp';
    case EMAIL = 'email';
    case SMS = 'sms';

    public function label(): string
    {
        return match ($this) {
            self::WHATSAPP => __('WhatsApp'),
            self::EMAIL => __('Email'),
            self::SMS => __('SMS'),
        };
    }

    /** @return array<string, string> value => label */
    public static function options(): array
    {
        return array_column(array_map(fn (self $channel): array => [$channel->value, $channel->label()], self::cases()), 1, 0);
    }

    /**
     * Only the ways there are, each once, in their own order.
     *
     * @param  iterable<mixed>  $values
     * @return list<string>
     */
    public static function clean(iterable $values): array
    {
        $values = collect($values)->map(fn ($value): string => $value instanceof self ? $value->value : (string) $value)->all();

        return array_values(array_filter(array_column(self::cases(), 'value'), fn (string $value): bool => in_array($value, $values, true)));
    }

    /** @param  iterable<mixed>  $values */
    public static function labels(iterable $values, string $glue = '، '): string
    {
        return implode($glue, array_map(fn (string $value): string => self::from($value)->label(), self::clean($values)));
    }
}
