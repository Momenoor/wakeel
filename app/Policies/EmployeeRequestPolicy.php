<?php

namespace App\Policies;

use App\Models\EmployeeRequest;
use App\Models\User;
use App\Services\MMS\EmployeeRequestService;

/**
 * Employee requests: each employee their own — made, seen, and changed or withdrawn while pending; whoever
 * decides them (Approve) and whoever pays them (Pay) everyone's.
 */
class EmployeeRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('ViewAny:EmployeeRequest') || $this->manageOthers($user);
    }

    public function view(User $user, EmployeeRequest $request): bool
    {
        return $this->manageOthers($user) || ($user->can('View:EmployeeRequest') && $this->isOwn($user, $request));
    }

    public function create(User $user): bool
    {
        return $user->can('Create:EmployeeRequest');
    }

    public function update(User $user, EmployeeRequest $request): bool
    {
        return $request->isPending() && $user->can('Update:EmployeeRequest') && ($this->isOwn($user, $request) || $this->manageOthers($user));
    }

    public function delete(User $user, EmployeeRequest $request): bool
    {
        return $request->isPending() && $user->can('Delete:EmployeeRequest') && ($this->isOwn($user, $request) || $this->manageOthers($user));
    }

    public function approve(User $user, ?EmployeeRequest $request = null): bool
    {
        return $user->can(EmployeeRequestService::APPROVE);
    }

    public function pay(User $user, ?EmployeeRequest $request = null): bool
    {
        return $user->can(EmployeeRequestService::PAY);
    }

    /** Sees and files everyone's: who decides them, and who pays them. */
    public function manageOthers(User $user): bool
    {
        return $user->can(EmployeeRequestService::APPROVE) || $user->can(EmployeeRequestService::PAY);
    }

    private function isOwn(User $user, EmployeeRequest $request): bool
    {
        return (int) $request->party?->user_id === (int) $user->getKey() || (int) $request->requested_by === (int) $user->getKey();
    }
}
