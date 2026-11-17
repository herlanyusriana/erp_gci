<?php

namespace App\Policies;

use App\Models\Uom;
use App\Models\User;

class UomPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyPermission(['uom.view', 'master.uom.view']);
    }

    public function view(User $user, Uom $uom): bool
    {
        return $user->hasAnyPermission(['uom.view', 'master.uom.view']);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyPermission(['uom.create', 'master.uom.create']);
    }

    public function update(User $user, Uom $uom): bool
    {
        return $user->hasAnyPermission(['uom.update', 'master.uom.update']);
    }

    public function delete(User $user, Uom $uom): bool
    {
        return $user->hasAnyPermission(['uom.delete', 'master.uom.delete']);
    }
}
