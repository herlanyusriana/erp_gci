<?php

namespace App\Policies;

use App\Models\IncomingReceive;
use App\Models\User;

class IncomingReceivePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyPermission(['receive.view', 'incoming.view']);
    }

    public function view(User $user, IncomingReceive $receive): bool
    {
        return $user->hasAnyPermission(['receive.view', 'incoming.view']);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyPermission(['receive.create', 'incoming.update']);
    }

    public function update(User $user, IncomingReceive $receive): bool
    {
        return $user->hasAnyPermission(['receive.update']);
    }

    public function delete(User $user, IncomingReceive $receive): bool
    {
        return $user->hasAnyPermission(['receive.delete']);
    }
}