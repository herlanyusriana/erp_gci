<?php

namespace App\Policies;

use App\Models\PurchaseOrder;
use App\Models\User;

class PurchaseOrderPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyPermission(['purchase_order.view', 'incoming.view']);
    }

    public function view(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return $user->hasAnyPermission(['purchase_order.view', 'incoming.view']);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyPermission(['purchase_order.create', 'incoming.create']);
    }

    public function update(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return $user->hasAnyPermission(['purchase_order.update', 'incoming.update']);
    }

    public function delete(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return $user->hasAnyPermission(['purchase_order.delete', 'incoming.delete']);
    }
}