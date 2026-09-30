<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Letterhead;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class LetterheadPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Letterhead');
    }

    public function view(AuthUser $authUser, Letterhead $letterhead): bool
    {
        return $authUser->can('View:Letterhead');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Letterhead');
    }

    public function update(AuthUser $authUser, Letterhead $letterhead): bool
    {
        return $authUser->can('Update:Letterhead');
    }

    public function delete(AuthUser $authUser, Letterhead $letterhead): bool
    {
        return $authUser->can('Delete:Letterhead');
    }

    public function restore(AuthUser $authUser, Letterhead $letterhead): bool
    {
        return $authUser->can('Restore:Letterhead');
    }

    public function forceDelete(AuthUser $authUser, Letterhead $letterhead): bool
    {
        return $authUser->can('ForceDelete:Letterhead');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Letterhead');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Letterhead');
    }

    public function replicate(AuthUser $authUser, Letterhead $letterhead): bool
    {
        return $authUser->can('Replicate:Letterhead');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Letterhead');
    }
}
