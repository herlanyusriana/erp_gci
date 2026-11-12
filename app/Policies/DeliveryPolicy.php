<?php

namespace App\Policies;

use App\Models\Delivery;
use App\Models\User;

class DeliveryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyPermission(['delivery.view', 'stock.issue']);
    }

    public function view(User $user, Delivery $delivery): bool
    {
        return $user->hasAnyPermission(['delivery.view', 'stock.issue']);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyPermission(['delivery.create']);
    }

    public function update(User $user, Delivery $delivery): bool
    {
        return $user->hasAnyPermission(['delivery.update']);
    }

    public function delete(User $user, Delivery $delivery): bool
    {
        return $user->hasAnyPermission(['delivery.delete']);
    }
}
