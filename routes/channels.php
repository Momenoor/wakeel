<?php

use App\Models\BulkMailCampaign;
use App\Models\MatterMinutes;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// A bulk mail campaign's live progress — for whoever may view the campaign.
Broadcast::channel('bulk-mail-campaign.{campaignId}', function ($user, $campaignId) {
    $campaign = BulkMailCampaign::find($campaignId);

    return $campaign !== null && $user->can('view', $campaign);
});

// A meeting's minutes, for the live view the attendees watch — whoever may view the matter.
Broadcast::channel('minutes.{minutesId}', function ($user, $minutesId) {
    $minutes = MatterMinutes::with('matter')->find($minutesId);

    return $minutes?->matter !== null && $user->can('view', $minutes->matter);
});

// Something the dashboards and the lists' counts show has changed (RecordsChanged) — for anyone signed in;
// it carries nothing but the table's name.
Broadcast::channel('records', fn ($user) => $user !== null);

// Presence channel every signed-in user joins from the chat widget — who
// is online, live, instead of waiting on last_seen_at and polling.
Broadcast::channel('online', function ($user) {
    return ['id' => $user->id];
});
