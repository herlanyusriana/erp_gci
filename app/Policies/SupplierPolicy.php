<?php

namespace App\Policies;

use App\Models\Supplier;
use App\Models\User;

class SupplierPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyPermission(['supplier.view', 'master.supplier.view']);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyPermission(['supplier.create', 'master.supplier.create']);
    }

    public function update(User $user, Supplier $supplier): bool
    {
        return $user->hasAnyPermission(['supplier.update', 'master.supplier.update']);
    }

    public function delete(User $user, Supplier $supplier): bool
    {
        return $user->hasAnyPermission(['supplier.delete', 'master.supplier.delete']);
    }
}