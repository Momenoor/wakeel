<?php

namespace App\Policies;

use App\Models\SignatureStamp;
use App\Models\User;
use App\Support\ScreenPermissions;

/**
 * Who may see, use and change a signature or a stamp: its owner always; anyone allowed the office's when it
 * is shared; and only those who manage the office's may change or delete a shared one that is not theirs.
 * (The image route and the editor check SignatureStamp::isUsableBy() themselves, so a super admin's pass
 * through every gate never reaches another person's own signature.)
 */
class SignatureStampPolicy
{
    /** The library: whoever signs PDFs, or looks after the office's signatures. */
    public function viewAny(User $user): bool
    {
        return $user->can(ScreenPermissions::SIGN_PDF) || $user->can(ScreenPermissions::MANAGE_SHARED_SIGNATURES);
    }

    public function view(User $user, SignatureStamp $stamp): bool
    {
        return $stamp->isUsableBy($user);
    }

    /** Putting it on a PDF. */
    public function use(User $user, SignatureStamp $stamp): bool
    {
        return $stamp->isUsableBy($user);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, SignatureStamp $stamp): bool
    {
        return (int) $stamp->user_id === (int) $user->getKey()
            || ($stamp->is_shared && $user->can(ScreenPermissions::MANAGE_SHARED_SIGNATURES));
    }

    public function delete(User $user, SignatureStamp $stamp): bool
    {
        return $this->update($user, $stamp);
    }

    public function deleteAny(User $user): bool
    {
        return $this->viewAny($user);
    }

    /** Making one the office's, or taking it back. */
    public function share(User $user): bool
    {
        return $user->can(ScreenPermissions::MANAGE_SHARED_SIGNATURES);
    }
}
