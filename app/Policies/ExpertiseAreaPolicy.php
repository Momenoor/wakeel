<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ExpertiseArea;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class ExpertiseAreaPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:ExpertiseArea');
    }

    public function view(AuthUser $authUser, ExpertiseArea $expertiseArea): bool
    {
        return $authUser->can('View:ExpertiseArea');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:ExpertiseArea');
    }

    public function update(AuthUser $authUser, ExpertiseArea $expertiseArea): bool
    {
        return $authUser->can('Update:ExpertiseArea');
    }

    public function delete(AuthUser $authUser, ExpertiseArea $expertiseArea): bool
    {
        return $authUser->can('Delete:ExpertiseArea');
    }

    public function restore(AuthUser $authUser, ExpertiseArea $expertiseArea): bool
    {
        return $authUser->can('Restore:ExpertiseArea');
    }

    public function forceDelete(AuthUser $authUser, ExpertiseArea $expertiseArea): bool
    {
        return $authUser->can('ForceDelete:ExpertiseArea');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:ExpertiseArea');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:ExpertiseArea');
    }

    public function replicate(AuthUser $authUser, ExpertiseArea $expertiseArea): bool
    {
        return $authUser->can('Replicate:ExpertiseArea');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:ExpertiseArea');
    }
}
