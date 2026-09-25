<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Presence channel every signed-in user joins from the chat widget — who
// is online, live, instead of waiting on last_seen_at and polling.
Broadcast::channel('online', function ($user) {
    return ['id' => $user->id];
});
