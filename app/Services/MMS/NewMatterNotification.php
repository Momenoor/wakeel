<?php

namespace App\Services\MMS;

use App\Enums\NotificationChannel;
use App\Enums\NotificationType;
use App\Enums\RequestStatus;
use App\Enums\RequestType;
use App\Mail\NewMatterNotificationMail;
use App\Models\Matter;
use App\Models\MatterRequest;
use App\Services\Notifications\NotificationPreferences;
use App\Services\Notifications\Notifier;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;

class NewMatterNotification
{
    private static function url(string $route, mixed $parameter): ?string
    {
        return Route::has($route) ? route($route, $parameter) : null;
    }

    public function sendToAssistants(Matter $matter): void
    {
        // Guard: already has a pending request
        if (MatterRequest::where('matter_id', $matter->id)
            ->where('type', RequestType::CHANGE_DISTRIBUTED_DATE)
            ->exists()) {
            Log::info("NewMatterNotification: Matter #{$matter->id} already has a pending request, skipping.");

            return;
        }

        $matter->load(['assistantsOnly.party.administrators', 'court', 'type']);

        $assistants = $matter->assistantsOnly->filter(fn ($mp) => $mp->party?->email);

        if ($assistants->isEmpty()) {
            Log::info("NewMatterNotification: Matter #{$matter->id} has no assistants with email.");

            return;
        }

        foreach ($assistants as $mp) {
            $party = $mp->party;

            try {
                $matterRequest = MatterRequest::create([
                    'matter_id' => $matter->id,
                    'request_by' => $party->user_id ?? null,
                    'type' => RequestType::CHANGE_DISTRIBUTED_DATE->value,
                    'status' => RequestStatus::PENDING->value,
                    'comment' => __('Auto-generated: awaiting assistant confirmation of received date.'),
                    'extra' => [
                        'party_id' => $party->id,
                        'party_name' => $party->name,
                        'current_distributed_at' => $matter->distributed_at,
                    ],
                ]);

                // An assistant with a user is told as that user chose; one with none, by email as always.
                $user = $party->user;
                $type = NotificationType::MATTER_ASSIGNED;

                if (! $user || NotificationPreferences::wants($user, $type, NotificationChannel::MAIL)) {
                    Mail::to($party->email)
                        ->locale('ar')
                        ->queue(new NewMatterNotificationMail($matter, $party, $matterRequest));

                    Log::info("NewMatterNotification: Mail queued for party #{$party->id} on Matter #{$matter->id}.");
                }

                $body = __('Matter :number/:year — :type — :court. Please confirm the received date.', [
                    'number' => $matter->number,
                    'year' => $matter->year,
                    'type' => $matter->type?->getAttribute('name') ?? '—',
                    'court' => $matter->court?->getAttribute('name') ?? '—',
                ]);
                $url = self::url('filament.mms.resources.matter-requests.view', $matterRequest);

                if ($user) {
                    // The rest in the system — bell, desktop and phone — and on WhatsApp, linked to
                    // the request where the received date is confirmed.
                    Notifier::send($type, [$user], __('New matter assigned'), $body, $url, only: [NotificationChannel::BELL, NotificationChannel::WHATSAPP]);
                }

                // Those who handle this assistant's matters with them: told as they chose.
                $administrators = $party->administrators->reject(fn ($admin) => $admin->is($user));
                if ($administrators->isNotEmpty()) {
                    Notifier::send($type, $administrators, __('New matter assigned to :name', ['name' => $party->name]), $body, $url);
                }

            } catch (\Throwable $e) {
                Log::error("NewMatterNotification: Failed to process party #{$party->id} on Matter #{$matter->id}.", [
                    'error' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]);
                // continues to next assistant instead of crashing the whole loop
            }
        }
    }
}
