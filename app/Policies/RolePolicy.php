<?php

namespace App\Policies;

use App\Models\Role;
use App\Models\User;

class RolePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyPermission(['role.view', 'role.update']);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyPermission(['role.create', 'role.update']);
    }

    public function update(User $user, Role $role): bool
    {
        return $user->hasAnyPermission(['role.update']);
    }

    public function delete(User $user, Role $role): bool
    {
        return $user->hasPermission('role.delete') && $role->name !== 'super-admin';
    }
}