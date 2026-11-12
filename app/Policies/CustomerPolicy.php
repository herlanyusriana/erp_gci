<?php

namespace App\Policies;

use App\Models\Customer;
use App\Models\User;

class CustomerPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyPermission(['customer.view', 'master.customer.view']);
    }

    public function view(User $user, Customer $customer): bool
    {
        return $user->hasAnyPermission(['customer.view', 'master.customer.view']);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyPermission(['customer.create', 'master.customer.create']);
    }

    public function update(User $user, Customer $customer): bool
    {
        return $user->hasAnyPermission(['customer.update', 'master.customer.update']);
    }

    public function delete(User $user, Customer $customer): bool
    {
        return $user->hasAnyPermission(['customer.delete', 'master.customer.delete']);
    }
}
