<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\SignatureLayout;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class SignatureLayoutPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:SignatureLayout');
    }

    public function view(AuthUser $authUser, SignatureLayout $signatureLayout): bool
    {
        return $authUser->can('View:SignatureLayout');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:SignatureLayout');
    }

    public function update(AuthUser $authUser, SignatureLayout $signatureLayout): bool
    {
        return $authUser->can('Update:SignatureLayout');
    }

    public function delete(AuthUser $authUser, SignatureLayout $signatureLayout): bool
    {
        return $authUser->can('Delete:SignatureLayout');
    }

    public function restore(AuthUser $authUser, SignatureLayout $signatureLayout): bool
    {
        return $authUser->can('Restore:SignatureLayout');
    }

    public function forceDelete(AuthUser $authUser, SignatureLayout $signatureLayout): bool
    {
        return $authUser->can('ForceDelete:SignatureLayout');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:SignatureLayout');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:SignatureLayout');
    }

    public function replicate(AuthUser $authUser, SignatureLayout $signatureLayout): bool
    {
        return $authUser->can('Replicate:SignatureLayout');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:SignatureLayout');
    }
}
