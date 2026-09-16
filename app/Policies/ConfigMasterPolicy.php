<?php

namespace App\Policies;

use App\Models\ConfigMaster;
use App\Models\User;

class ConfigMasterPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyPermission(['config.view', 'config.update']);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('config.update');
    }

    public function update(User $user, ConfigMaster $config): bool
    {
        return $user->hasPermission('config.update');
    }

    public function delete(User $user, ConfigMaster $config): bool
    {
        return $user->hasPermission('config.update');
    }
}