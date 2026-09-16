<?php

namespace App\Policies;

use App\Models\TruckingCompany;
use App\Models\User;

class TruckingCompanyPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyPermission(['trucking.view', 'master.trucking.view']);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyPermission(['trucking.create', 'master.trucking.create']);
    }

    public function update(User $user, TruckingCompany $trucking): bool
    {
        return $user->hasAnyPermission(['trucking.update', 'master.trucking.update']);
    }

    public function delete(User $user, TruckingCompany $trucking): bool
    {
        return $user->hasAnyPermission(['trucking.delete', 'master.trucking.delete']);
    }
}
