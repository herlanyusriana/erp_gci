<?php

namespace App\Policies;

use App\Models\Bom;
use App\Models\User;

class BomPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyPermission(['bom.view', 'master.bom.view']);
    }

    public function view(User $user, Bom $bom): bool
    {
        return $user->hasAnyPermission(['bom.view', 'master.bom.view']);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyPermission(['bom.create', 'master.bom.create']);
    }

    public function update(User $user, Bom $bom): bool
    {
        return $user->hasAnyPermission(['bom.update', 'master.bom.update']);
    }

    public function delete(User $user, Bom $bom): bool
    {
        return $user->hasAnyPermission(['bom.delete', 'master.bom.delete']);
    }
}