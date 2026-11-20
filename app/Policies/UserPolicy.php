<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyPermission(['user.view', 'user.update']);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyPermission(['user.create', 'user.update']);
    }

    public function update(User $user, User $model): bool
    {
        if ($this->isSuperAdmin($model) && ! $this->isSuperAdmin($user)) {
            return false;
        }

        // Cannot edit your own roles/status to prevent lockout of the last admin.
        return $user->hasPermission('user.update') && $model->id !== $user->id;
    }

    public function delete(User $user, User $model): bool
    {
        if ($this->isSuperAdmin($model) && ! $this->isSuperAdmin($user)) {
            return false;
        }

        return $user->hasPermission('user.delete') && $model->id !== $user->id;
    }

    private function isSuperAdmin(User $user): bool
    {
        return $user->roles()->where('name', 'super-admin')->exists();
    }
}
