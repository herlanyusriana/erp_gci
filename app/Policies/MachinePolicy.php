<?php

namespace App\Policies;

use App\Models\Machine;
use App\Models\User;

class MachinePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyPermission(['machine.view', 'master.machine.view']);
    }

    public function view(User $user, Machine $machine): bool
    {
        return $user->hasAnyPermission(['machine.view', 'master.machine.view']);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyPermission(['machine.create', 'master.machine.create']);
    }

    public function update(User $user, Machine $machine): bool
    {
        return $user->hasAnyPermission(['machine.update', 'master.machine.update']);
    }

    public function delete(User $user, Machine $machine): bool
    {
        return $user->hasAnyPermission(['machine.delete', 'master.machine.delete']);
    }
}
