<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\LetterFont;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class LetterFontPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:LetterFont');
    }

    public function view(AuthUser $authUser, LetterFont $letterFont): bool
    {
        return $authUser->can('View:LetterFont');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:LetterFont');
    }

    public function update(AuthUser $authUser, LetterFont $letterFont): bool
    {
        return $authUser->can('Update:LetterFont');
    }

    public function delete(AuthUser $authUser, LetterFont $letterFont): bool
    {
        return $authUser->can('Delete:LetterFont');
    }

    public function restore(AuthUser $authUser, LetterFont $letterFont): bool
    {
        return $authUser->can('Restore:LetterFont');
    }

    public function forceDelete(AuthUser $authUser, LetterFont $letterFont): bool
    {
        return $authUser->can('ForceDelete:LetterFont');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:LetterFont');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:LetterFont');
    }

    public function replicate(AuthUser $authUser, LetterFont $letterFont): bool
    {
        return $authUser->can('Replicate:LetterFont');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:LetterFont');
    }
}
