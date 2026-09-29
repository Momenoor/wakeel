<?php

namespace App\Services\MMS;

use App\Enums\RequestStatus;
use App\Enums\RequestType;
use App\Mail\NewMatterNotificationMail;
use App\Models\Matter;
use App\Models\MatterRequest;
use App\Services\Notify\UserAlert;
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

        $matter->load(['assistantsOnly.party', 'court', 'type']);

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

                Mail::to($party->email)
                    ->locale('ar')
                    ->queue(new NewMatterNotificationMail($matter, $party, $matterRequest));

                Log::info("NewMatterNotification: Mail queued for party #{$party->id} on Matter #{$matter->id}.");

                // And in the system — bell, desktop and phone — linked to
                // the request where the received date is confirmed.
                UserAlert::send(
                    $party->user,
                    __('New matter assigned'),
                    __('Matter :number/:year — :type — :court. Please confirm the received date.', [
                        'number' => $matter->number,
                        'year' => $matter->year,
                        'type' => $matter->type?->getAttribute('name') ?? '—',
                        'court' => $matter->court?->getAttribute('name') ?? '—',
                    ]),
                    self::url('filament.mms.resources.matter-requests.view', $matterRequest),
                );

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
