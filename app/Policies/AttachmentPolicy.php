<?php

namespace App\Policies;

use App\Models\Attachment;
use App\Models\Matter;
use App\Models\User;

/**
 * Who may open, add to and delete a file: whoever may with its matter — the context every file has
 * today. (Financial categories can be narrowed further here when they are given their own rule.)
 */
class AttachmentPolicy
{
    public function view(User $user, Attachment $attachment): bool
    {
        return $this->matterAllows($user, $attachment, 'view');
    }

    public function create(User $user, ?Matter $matter = null): bool
    {
        return $matter ? $user->can('update', $matter) : true;
    }

    /** Whoever made it, or whoever may change its matter. */
    public function delete(User $user, Attachment $attachment): bool
    {
        return (int) $attachment->user_id === (int) $user->getKey() || $this->matterAllows($user, $attachment, 'update');
    }

    private function matterAllows(User $user, Attachment $attachment, string $ability): bool
    {
        $matter = $attachment->matter;

        // With no matter (a party's file, an employee's): its owner decides — no one yet but who made it.
        return $matter ? $user->can($ability, $matter) : (int) $attachment->user_id === (int) $user->getKey();
    }
}
