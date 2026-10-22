<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\HrListItem;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class HrListItemPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:HrListItem');
    }

    public function view(AuthUser $authUser, HrListItem $item): bool
    {
        return $authUser->can('View:HrListItem');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:HrListItem');
    }

    public function update(AuthUser $authUser, HrListItem $item): bool
    {
        return $authUser->can('Update:HrListItem');
    }

    public function delete(AuthUser $authUser, HrListItem $item): bool
    {
        return $authUser->can('Delete:HrListItem');
    }
}
