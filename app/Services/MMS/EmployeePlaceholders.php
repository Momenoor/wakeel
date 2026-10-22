<?php

namespace App\Services\MMS;

use App\Enums\SalaryComponent;
use App\Models\EmployeeRequest;
use Illuminate\Support\Carbon;
use NumberFormatter;

/**
 * The placeholders of an employee's certificate (a letter template of the Certificates category, issued for an
 * Employee request): {{employee.*}} — who they are and what they earn, as it stands on the day it is issued —
 * and {{request.*}}, what they asked it for.
 */
class EmployeePlaceholders
{
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

        $values = [
            'employee.name' => (string) ($profile?->display_name ?: $party?->name),
            'employee.designation' => (string) $profile?->designation,
            'employee.employee_no' => (string) $profile?->employee_no,
            'employee.joining_date' => (string) $profile?->date_of_joining?->format('d/m/Y'),
            'employee.passport_no' => (string) $profile?->passport_no,
            'employee.emirates_id' => (string) $profile?->emirates_id_no,
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
        $catalog = [
            'employee.name' => __('Employee').' — '.__('name'),
            'employee.designation' => __('Employee').' — '.__('designation'),
            'employee.employee_no' => __('Employee').' — '.__('employee number'),
            'employee.joining_date' => __('Employee').' — '.__('joining date'),
            'employee.passport_no' => __('Employee').' — '.__('passport number'),
            'employee.emirates_id' => __('Employee').' — '.__('Emirates ID'),
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
