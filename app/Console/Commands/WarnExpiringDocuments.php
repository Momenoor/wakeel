<?php

namespace App\Console\Commands;

use App\Enums\NotificationType;
use App\Filament\Mms\Resources\Companies\CompanyResource;
use App\Filament\Mms\Resources\EmployeeProfiles\EmployeeProfileResource;
use App\Filament\Support\ExpiringDocuments;
use App\Models\Company;
use App\Models\EmployeeProfile;
use App\Models\Setting;
use App\Models\User;
use App\Services\Notifications\Notifier;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The alarm for the documents that expire — an employee's passport, Emirates ID, labour card and residency (while
 * they are on staff), a company's trade licence and establishment card: on the days in ExpiringDocuments::WARN_AT
 * (60, 30 and 7 days before, and the day itself) whoever looks after them is told, on the bell and as they chose
 * (Notification preferences) — the employees' to whoever may change employees, the companies' to whoever may
 * change companies. Run daily.
 */
class WarnExpiringDocuments extends Command
{
    protected $signature = 'documents:warn-expiring';

    protected $description = 'Tell whoever looks after them of the employees\' and companies\' documents expiring in 60, 30 or 7 days, or today';

    /** The day it last told (Y-m-d). */
    private const LAST_RUN = 'documents_expiring_warned_on';

    /** From 8 in the morning (Dubai), once a day. */
    public static function isDue(): bool
    {
        $now = now('Asia/Dubai');

        return $now->hour >= 8 && Setting::get(self::LAST_RUN) !== $now->toDateString();
    }

    public function handle(): int
    {
        Setting::set(self::LAST_RUN, now('Asia/Dubai')->toDateString(), 'system');

        $told = $this->tell(
            EmployeeProfile::query()->whereNull('date_of_leaving')->with('party'),
            EmployeeProfile::EXPIRING,
            'Update:EmployeeProfile',
            fn (EmployeeProfile $profile): string => (string) ($profile->party?->name ?: $profile->display_name ?: $profile->employee_no),
            fn (EmployeeProfile $profile): string => EmployeeProfileResource::getUrl('edit', ['record' => $profile], panel: 'mms'),
        ) + $this->tell(
            Company::query(),
            Company::EXPIRING,
            'Update:Company',
            fn (Company $company): string => $company->displayName(),
            fn (Company $company): string => CompanyResource::getUrl('edit', ['record' => $company], panel: 'mms'),
        );

        $this->info(__('Expiring documents told: :count', ['count' => $told]));

        return self::SUCCESS;
    }

    /**
     * Each document of the records falling due on one of the warning days told to the permission's holders, one
     * notification each. How many were.
     *
     * @param  array<string, string>  $documents  column => label
     * @param  Closure(Model): string  $name
     * @param  Closure(Model): string  $url
     */
    private function tell(Builder $query, array $documents, string $permission, Closure $name, Closure $url): int
    {
        $days = array_map(fn (int $days): string => today()->addDays($days)->toDateString(), ExpiringDocuments::WARN_AT);

        $records = $query->where(function (Builder $query) use ($documents, $days): void {
            foreach (array_keys($documents) as $column) {
                foreach ($days as $day) {
                    $query->orWhereDate($column, $day);
                }
            }
        })->get();

        if ($records->isEmpty()) {
            return 0;
        }

        $users = User::query()->holding($permission)->get();
        $told = 0;

        foreach ($records as $record) {
            foreach ($documents as $column => $label) {
                $date = $record->getAttribute($column);

                if (! $date instanceof CarbonInterface || ! in_array($date->toDateString(), $days, true)) {
                    continue;
                }

                Notifier::send(
                    NotificationType::DOCUMENTS_EXPIRING,
                    $users,
                    __(':document of :name', ['document' => __($label), 'name' => $name($record)]),
                    ExpiringDocuments::describe(__($label), $date),
                    $url($record),
                );
                $told++;
            }
        }

        return $told;
    }
}
