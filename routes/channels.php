<?php

use App\Models\BulkMailCampaign;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// A bulk mail campaign's live progress — for whoever may view the campaign.
Broadcast::channel('bulk-mail-campaign.{campaignId}', function ($user, $campaignId) {
    $campaign = BulkMailCampaign::find($campaignId);

    return $campaign !== null && $user->can('view', $campaign);
});

// Presence channel every signed-in user joins from the chat widget — who
// is online, live, instead of waiting on last_seen_at and polling.
Broadcast::channel('online', function ($user) {
    return ['id' => $user->id];
});
