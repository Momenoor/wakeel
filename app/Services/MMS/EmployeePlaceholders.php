<?php

namespace App\Services\MMS;

use App\Enums\SalaryComponent;
use App\Models\EmployeeRequest;
use App\Support\Nationalities;
use Illuminate\Support\Carbon;
use NumberFormatter;

/**
 * The placeholders of an employee's certificate (a letter template of the Certificates category, issued for an
 * Employee request): {{employee.*}} — who they are, their documents and what they earn, as it stands on the
 * day it is issued — and {{request.*}}, what they asked it for. Their name, designation and nationality in the
 * letter's language ({{employee.name}}), or in either ({{employee.name_ar}}, {{employee.name_en}}).
 */
class EmployeePlaceholders
{
    /** Their documents: the profile's number and expiry fields, as {{employee.KEY}} and {{employee.KEY_expiry}}. */
    private const DOCUMENTS = [
        'passport' => ['passport_no', 'passport_expiry'],
        'emirates_id' => ['emirates_id_no', 'emirates_id_expiry'],
        'labour_card' => ['labour_card_no', 'labour_card_expiry'],
        'residency' => ['residency_visa_no', 'residency_expiry'],
    ];

    /**
     * @return array<string, string>
     */
    public static function values(EmployeeRequest $request, ?Carbon $on = null): array
    {
        $on ??= now();
        $party = $request->party;
        $profile = $party?->employeeProfile;
        $salary = $party ? app(PayrollService::class)->salaryAt((int) $party->getKey(), $on) : collect();
        $total = (float) $salary->sum();
        $amount = fn (float $value): string => number_format($value, 2);
        $arabic = $request->template?->locale !== 'en';

        // Each in Arabic and English — the name the WPS form's in English, else as their party is named.
        $said = [
            'name' => [(string) ($profile?->name_ar ?: $party?->name), (string) ($profile?->display_name ?: $party?->name)],
            'designation' => [(string) ($profile?->designationItem?->name(true) ?: $profile?->designation), (string) ($profile?->designationItem?->name(false) ?: $profile?->designation)],
            'nationality' => [Nationalities::name($profile?->nationality, true), Nationalities::name($profile?->nationality, false)],
        ];

        $values = [];

        foreach ($said as $key => [$inArabic, $inEnglish]) {
            $values['employee.'.$key] = $arabic ? $inArabic : $inEnglish;
            $values['employee.'.$key.'_ar'] = $inArabic;
            $values['employee.'.$key.'_en'] = $inEnglish;
        }

        foreach (self::DOCUMENTS as $key => [$number, $expiry]) {
            $values['employee.'.$key] = (string) $profile?->getAttribute($number);
            $values['employee.'.$key.'_expiry'] = (string) $profile?->getAttribute($expiry)?->format('d/m/Y');
        }

        $values = [
            ...$values,
            'employee.employee_no' => (string) $profile?->employee_no,
            'employee.joining_date' => (string) $profile?->date_of_joining?->format('d/m/Y'),
            // As it was named before: the passport's number.
            'employee.passport_no' => (string) $profile?->passport_no,
            'employee.salary.total' => $amount($total),
            'employee.salary.total.words' => self::words($total),
            'request.addressed_to' => (string) $request->addressed_to,
            'request.purpose' => (string) $request->purpose,
        ];

        foreach (SalaryComponent::cases() as $component) {
            $values['employee.salary.'.$component->value] = $amount((float) ($salary[$component->value] ?? 0));
        }

        return $values;
    }

    /**
     * What each is, for the template editor's menu.
     *
     * @return array<string, string>
     */
    public static function catalog(): array
    {
        $what = ['name' => __('name'), 'designation' => __('designation'), 'nationality' => __('nationality')];
        $documents = ['passport' => __('passport'), 'emirates_id' => __('Emirates ID'), 'labour_card' => __('labour card'), 'residency' => __('residency')];
        $catalog = [];

        foreach ($what as $key => $label) {
            $catalog['employee.'.$key] = __('Employee').' — '.$label.' '.__('(in the letter\'s language)');
            $catalog['employee.'.$key.'_ar'] = __('Employee').' — '.$label.' '.__('(Arabic)');
            $catalog['employee.'.$key.'_en'] = __('Employee').' — '.$label.' '.__('(English)');
        }

        foreach ($documents as $key => $label) {
            $catalog['employee.'.$key] = __('Employee').' — '.$label.' — '.__('number');
            $catalog['employee.'.$key.'_expiry'] = __('Employee').' — '.$label.' — '.__('expiry date');
        }

        $catalog += [
            'employee.employee_no' => __('Employee').' — '.__('employee number'),
            'employee.joining_date' => __('Employee').' — '.__('joining date'),
            'employee.salary.total' => __('Salary').' — '.__('total'),
            'employee.salary.total.words' => __('Salary').' — '.__('total, in words'),
        ];

        foreach (SalaryComponent::cases() as $component) {
            $catalog['employee.salary.'.$component->value] = __('Salary').' — '.$component->getLabel();
        }

        return [
            ...$catalog,
            'request.addressed_to' => __('Addressed to'),
            'request.purpose' => __('Purpose'),
        ];
    }

    /** "اثنا عشر ألف وخمسمائة": the whole amount in Arabic words (the fils left out). */
    private static function words(float $amount): string
    {
        return (string) rescue(fn () => (new NumberFormatter('ar', NumberFormatter::SPELLOUT))->format((int) floor($amount)), '', report: false);
    }
}
