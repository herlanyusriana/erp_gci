<?php

namespace App\Policies;

use App\Models\Part;
use App\Models\User;

class PartPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyPermission(['part.view', 'master.part.view']);
    }

    public function view(User $user, Part $part): bool
    {
        return $user->hasAnyPermission(['part.view', 'master.part.view']);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyPermission(['part.create', 'master.part.create']);
    }

    public function update(User $user, Part $part): bool
    {
        return $user->hasAnyPermission(['part.update', 'master.part.update']);
    }

    public function delete(User $user, Part $part): bool
    {
        return $user->hasAnyPermission(['part.delete', 'master.part.delete']);
    }
}