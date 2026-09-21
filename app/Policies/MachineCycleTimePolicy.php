<?php

namespace App\Policies;

use App\Models\MachineCycleTime;
use App\Models\User;

/**
 * Cycle time mesin adalah data master mesin → memakai permission `machine.*`.
 */
class MachineCycleTimePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyPermission(['machine.view', 'machine.update']);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyPermission(['machine.create', 'machine.update']);
    }

    public function update(User $user, MachineCycleTime $cycleTime): bool
    {
        return $user->hasAnyPermission(['machine.update']);
    }

    public function delete(User $user, MachineCycleTime $cycleTime): bool
    {
        return $user->hasAnyPermission(['machine.delete', 'machine.update']);
    }
}
