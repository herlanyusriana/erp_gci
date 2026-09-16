<?php

namespace App\Policies;

use App\Models\PartSubstitute;
use App\Models\User;

class PartSubstitutePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyPermission(['part_substitute.view', 'part.view']);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyPermission(['part_substitute.create', 'part.create']);
    }

    public function update(User $user, PartSubstitute $substitute): bool
    {
        return $user->hasAnyPermission(['part_substitute.update', 'part.update']);
    }

    public function delete(User $user, PartSubstitute $substitute): bool
    {
        return $user->hasAnyPermission(['part_substitute.delete', 'part.delete']);
    }
}