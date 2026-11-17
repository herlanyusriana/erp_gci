<?php

namespace App\Policies;

use App\Models\Process;
use App\Models\User;

class ProcessPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyPermission(['process.view', 'master.process.view']);
    }

    public function view(User $user, Process $process): bool
    {
        return $user->hasAnyPermission(['process.view', 'master.process.view']);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyPermission(['process.create', 'master.process.create']);
    }

    public function update(User $user, Process $process): bool
    {
        return $user->hasAnyPermission(['process.update', 'master.process.update']);
    }

    public function delete(User $user, Process $process): bool
    {
        return $user->hasAnyPermission(['process.delete', 'master.process.delete']);
    }
}
