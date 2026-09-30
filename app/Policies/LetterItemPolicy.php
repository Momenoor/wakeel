<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\LetterItem;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class LetterItemPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:LetterItem');
    }

    public function view(AuthUser $authUser, LetterItem $letterItem): bool
    {
        return $authUser->can('View:LetterItem');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:LetterItem');
    }

    public function update(AuthUser $authUser, LetterItem $letterItem): bool
    {
        return $authUser->can('Update:LetterItem');
    }

    public function delete(AuthUser $authUser, LetterItem $letterItem): bool
    {
        return $authUser->can('Delete:LetterItem');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:LetterItem');
    }

    public function restore(AuthUser $authUser, LetterItem $letterItem): bool
    {
        return $authUser->can('Restore:LetterItem');
    }

    public function forceDelete(AuthUser $authUser, LetterItem $letterItem): bool
    {
        return $authUser->can('ForceDelete:LetterItem');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:LetterItem');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:LetterItem');
    }

    public function replicate(AuthUser $authUser, LetterItem $letterItem): bool
    {
        return $authUser->can('Replicate:LetterItem');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:LetterItem');
    }
}
