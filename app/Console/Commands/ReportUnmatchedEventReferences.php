<?php

namespace App\Console\Commands;

use App\Filament\Mms\Pages\AdminDashboard;
use App\Mail\UnmatchedEventReferencesMail;
use App\Models\CalendarEvent;
use App\Models\User;
use App\Services\MMS\Calendar\UnmatchedEventReferences;
use BezhanSalleh\FilamentShield\Support\Utils;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Emails super admins and admins the calendar events that name a matter
 * number not in the system — daily (bootstrap/app.php), and only when there
 * are some.
 */
class ReportUnmatchedEventReferences extends Command
{
    protected $signature = 'calendar:report-unmatched';

    protected $description = 'Email admins the calendar events naming a matter number that is not in the system';

    public function handle(): int
    {
        UnmatchedEventReferences::forget();
        $missing = UnmatchedEventReferences::missing();

        if ($missing === []) {
            $this->info('Every matter number in the calendar matches a matter.');

            return self::SUCCESS;
        }

        $recipients = User::role(['admin', Utils::getSuperAdminName()])
            ->get()
            ->filter(fn (User $user) => filled($user->email))
            ->unique('email');

        if ($recipients->isEmpty()) {
            $this->warn('No super admin or admin with an email address.');

            return self::SUCCESS;
        }

        app()->setLocale('ar');

        $rows = UnmatchedEventReferences::events()->get()->map(fn (CalendarEvent $event) => [
            'date' => $event->start_datetime?->translatedFormat('D d/m/Y g:i A'),
            'title' => $event->title,
            'missing' => $missing[$event->id] ?? [],
        ])->all();

        $mail = new UnmatchedEventReferencesMail($rows, AdminDashboard::getUrl(panel: 'mms'));
        $sent = 0;

        foreach ($recipients as $user) {
            try {
                Mail::to($user->email)->locale('ar')->send(clone $mail);
                $sent++;
            } catch (Throwable $exception) {
                report($exception);
                $this->error("Could not email {$user->email}: {$exception->getMessage()}");
            }
        }

        $this->info(count($rows)." event(s) reported to {$sent} admin(s).");

        return self::SUCCESS;
    }
}
