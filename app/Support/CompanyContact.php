<?php

namespace App\Support;

use App\Models\Setting;

/**
 * The office's own contact details, set once in System Settings and used
 * wherever the office names how to reach it: the privacy policy and terms,
 * and as {{company.*}} in letters, emails and WhatsApp messages.
 */
class CompanyContact
{
    public const PHONE = 'company_phone';

    public const WHATSAPP = 'company_whatsapp';

    public const EMAIL = 'company_email';

    public const KEYS = [self::PHONE, self::WHATSAPP, self::EMAIL];

    public static function name(): string
    {
        return (string) (Setting::get('company_name') ?: Setting::get('app_name', config('app.name')));
    }

    public static function phone(): string
    {
        return (string) Setting::get(self::PHONE, '');
    }

    public static function whatsapp(): string
    {
        return (string) Setting::get(self::WHATSAPP, '');
    }

    public static function email(): string
    {
        return (string) Setting::get(self::EMAIL, '');
    }

    /**
     * @return array<string, string>
     */
    public static function values(): array
    {
        return [
            'company.name' => self::name(),
            'company.phone' => self::phone(),
            'company.whatsapp' => self::whatsapp(),
            'company.email' => self::email(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function catalog(): array
    {
        return [
            'company.name' => __('Company name'),
            'company.phone' => __('Company phone'),
            'company.whatsapp' => __('Company WhatsApp'),
            'company.email' => __('Company email'),
        ];
    }
}
