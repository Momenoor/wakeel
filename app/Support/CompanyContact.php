<?php

namespace App\Support;

use App\Models\Setting;

/**
 * The office's own details, set once in Settings → General and used wherever the office names itself or how
 * to reach it: the privacy policy and terms, and as {{company.*}} in letters, emails and WhatsApp messages —
 * its name and address in Arabic and English (a letter's own language: {{company.name}}; either:
 * {{company.name_ar}}, {{company.name_en}}), its trade licence number.
 */
class CompanyContact
{
    public const PHONE = 'company_phone';

    public const WHATSAPP = 'company_whatsapp';

    public const EMAIL = 'company_email';

    public const NAME_AR = 'company_name_ar';

    public const ADDRESS_EN = 'company_address_en';

    public const ADDRESS_AR = 'company_address_ar';

    public const LICENSE_NO = 'company_license_no';

    public const KEYS = [self::PHONE, self::WHATSAPP, self::EMAIL, self::NAME_AR, self::ADDRESS_EN, self::ADDRESS_AR, self::LICENSE_NO];

    /** Its name as set (in English, as it has always been typed) — or the system's. */
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
     * Its values, those said in two languages in the letter's own ($arabic) — or, not said, its name as set
     * (an email, a WhatsApp message) and its address in English.
     *
     * @return array<string, string>
     */
    public static function values(?bool $arabic = null): array
    {
        $nameEn = self::name();
        $nameAr = (string) (Setting::get(self::NAME_AR) ?: $nameEn);
        $addressEn = (string) Setting::get(self::ADDRESS_EN, '');
        $addressAr = (string) (Setting::get(self::ADDRESS_AR) ?: $addressEn);

        return [
            'company.name' => $arabic ? $nameAr : $nameEn,
            'company.name_ar' => $nameAr,
            'company.name_en' => $nameEn,
            'company.address' => $arabic ? $addressAr : $addressEn,
            'company.address_ar' => $addressAr,
            'company.address_en' => $addressEn,
            'company.license_no' => (string) Setting::get(self::LICENSE_NO, ''),
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
            'company.name' => __('Company name').' '.__('(in the letter\'s language)'),
            'company.name_ar' => __('Company name').' '.__('(Arabic)'),
            'company.name_en' => __('Company name').' '.__('(English)'),
            'company.address' => __('Company address').' '.__('(in the letter\'s language)'),
            'company.address_ar' => __('Company address').' '.__('(Arabic)'),
            'company.address_en' => __('Company address').' '.__('(English)'),
            'company.license_no' => __('Trade licence number'),
            'company.phone' => __('Company phone'),
            'company.whatsapp' => __('Company WhatsApp'),
            'company.email' => __('Company email'),
        ];
    }
}
