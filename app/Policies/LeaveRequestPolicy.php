<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\LeaveRequest;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Filing a leave request and deciding one are separate abilities: every employee
 * needs Create, only HR needs Approve.
 */
class LeaveRequestPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:LeaveRequest');
    }

    public function view(AuthUser $authUser, LeaveRequest $leaveRequest): bool
    {
        return $authUser->can('View:LeaveRequest');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:LeaveRequest');
    }

    public function update(AuthUser $authUser, LeaveRequest $leaveRequest): bool
    {
        return $authUser->can('Update:LeaveRequest');
    }

    public function delete(AuthUser $authUser, LeaveRequest $leaveRequest): bool
    {
        return $authUser->can('Delete:LeaveRequest');
    }

    public function restore(AuthUser $authUser, LeaveRequest $leaveRequest): bool
    {
        return $authUser->can('Restore:LeaveRequest');
    }

    public function forceDelete(AuthUser $authUser, LeaveRequest $leaveRequest): bool
    {
        return $authUser->can('ForceDelete:LeaveRequest');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:LeaveRequest');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:LeaveRequest');
    }

    public function replicate(AuthUser $authUser, LeaveRequest $leaveRequest): bool
    {
        return $authUser->can('Replicate:LeaveRequest');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:LeaveRequest');
    }

    public function approve(AuthUser $authUser, LeaveRequest $leaveRequest): bool
    {
        return $authUser->can('Approve:LeaveRequest');
    }

    /**
     * Whether this user sees everyone's requests and files for others, rather than only their own.
     *
     * Its own permission (ViewAll), not approving's: only a role given it sees everybody's — given at first
     * to those who approve leave, and changed in Roles. Everyone else files against their own party and
     * sees their own requests, and nothing else (an employee never reads a colleague's sick note).
     */
    public function viewAll(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAll:LeaveRequest');
    }

    public function manageOthers(AuthUser $authUser): bool
    {
        return $this->viewAll($authUser);
    }
}
