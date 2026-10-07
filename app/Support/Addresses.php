<?php

namespace App\Support;

use App\Services\WhatsAppService;

/**
 * Email addresses and phone numbers compared as what they reach, so nothing
 * is sent twice to the same place: "Info@Office.ae" and "info@office.ae"
 * are one inbox; 050…, +97150… and 0097150… one WhatsApp number.
 */
class Addresses
{
    /**
     * The valid addresses, each once (the first spelling kept), in order.
     *
     * @param  iterable<mixed>  $emails
     * @return list<string>
     */
    public static function emails(iterable $emails): array
    {
        $kept = [];

        foreach (collect($emails)->flatten() as $email) {
            $email = trim((string) $email);

            if (filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
                $kept[self::emailKey($email)] ??= $email;
            }
        }

        return array_values($kept);
    }

    public static function emailKey(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    public static function phoneKey(string $phone): string
    {
        return WhatsAppService::formatWhatsAppNumber($phone) ?? (preg_replace('/\D+/', '', $phone) ?? '');
    }

    /**
     * $emails without those in $already (compared as inboxes).
     *
     * @param  list<string>  $emails
     * @param  iterable<string>  $already
     * @return list<string>
     */
    public static function without(array $emails, iterable $already): array
    {
        $taken = collect($already)->map(fn ($email) => self::emailKey((string) $email))->flip();

        return array_values(array_filter($emails, fn (string $email): bool => ! $taken->has(self::emailKey($email))));
    }
}
