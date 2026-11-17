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

    /** OQC (pass/reject) punya izin sendiri supaya role QC tidak perlu delivery.update. */
    public function oqc(User $user, Delivery $delivery): bool
    {
        return $user->hasAnyPermission(['delivery.oqc']);
    }

    public function delete(User $user, Delivery $delivery): bool
    {
        return $user->hasAnyPermission(['delivery.delete']);
    }
}
