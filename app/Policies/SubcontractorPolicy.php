<?php

namespace App\Policies;

use App\Models\Subcontractor;
use App\Models\User;

class SubcontractorPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyPermission(['subcontractor.view', 'master.subcontractor.view']);
    }

    public function view(User $user, Subcontractor $subcontractor): bool
    {
        return $user->hasAnyPermission(['subcontractor.view', 'master.subcontractor.view']);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyPermission(['subcontractor.create', 'master.subcontractor.create']);
    }

    public function update(User $user, Subcontractor $subcontractor): bool
    {
        return $user->hasAnyPermission(['subcontractor.update', 'master.subcontractor.update']);
    }

    public function delete(User $user, Subcontractor $subcontractor): bool
    {
        return $user->hasAnyPermission(['subcontractor.delete', 'master.subcontractor.delete']);
    }
}
