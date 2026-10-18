<?php

namespace App\Livewire\Concerns;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;

/**
 * Echo listeners on the user's own channel (App.Models.User.{id}), named
 * after the user the page was opened as — not whoever is signed in when an
 * event arrives. A page opened as one user stays subscribed to that user's
 * channel after the session changes (impersonating, or signing in as someone
 * else in another tab); its events then named a handler the new user's
 * listener list did not have, and Livewire failed the request ("Handler for
 * event echo-private:App.Models.User.7,… does not exist").
 */
trait ListensOnOwnChannel
{
    #[Locked]
    public ?int $listenerUserId = null;

    public function mountListensOnOwnChannel(): void
    {
        $this->listenerUserId = Auth::id();
    }

    /** "echo-private:App.Models.User.7,.chat.message.sent" — the leading dot: broadcast under its own name. */
    protected function ownChannelEvent(string $event): string
    {
        return 'echo-private:App.Models.User.'.($this->listenerUserId ?? Auth::id()).',.'.$event;
    }

    /** Still signed in as the user the page was opened as — an event for anyone else is left alone. */
    protected function listenerIsSignedIn(): bool
    {
        return Auth::check() && ($this->listenerUserId === null || $this->listenerUserId === Auth::id());
    }
}
