<?php

namespace App\Policies;

use App\Models\PartPrice;
use App\Models\User;

/**
 * Price master = data pembelian → memakai permission `purchase_order.*`.
 */
class PartPricePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyPermission(['purchase_order.view', 'purchase_order.update']);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyPermission(['purchase_order.create', 'purchase_order.update']);
    }

    public function update(User $user, PartPrice $price): bool
    {
        return $user->hasAnyPermission(['purchase_order.update']);
    }

    public function delete(User $user, PartPrice $price): bool
    {
        return $user->hasAnyPermission(['purchase_order.delete', 'purchase_order.update']);
    }
}
