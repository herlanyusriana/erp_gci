<?php

namespace App\Policies;

use App\Models\PickingList;
use App\Models\User;

class PickingListPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyPermission(['delivery.view', 'stock.issue']);
    }

    public function view(User $user, PickingList $pickingList): bool
    {
        return $user->hasAnyPermission(['delivery.view', 'stock.issue']);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyPermission(['delivery.create']);
    }

    public function update(User $user, PickingList $pickingList): bool
    {
        return $user->hasAnyPermission(['delivery.update']);
    }

    public function delete(User $user, PickingList $pickingList): bool
    {
        return $user->hasAnyPermission(['delivery.delete']);
    }
}
