<?php

namespace App\Policies;

use App\Models\IncomingArrival;
use App\Models\User;

class IncomingArrivalPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyPermission(['incoming.view', 'receive.view']);
    }

    public function view(User $user, IncomingArrival $arrival): bool
    {
        return $user->hasAnyPermission(['incoming.view', 'receive.view']);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyPermission(['incoming.create']);
    }

    public function update(User $user, IncomingArrival $arrival): bool
    {
        return $user->hasAnyPermission(['incoming.update']);
    }

    public function delete(User $user, IncomingArrival $arrival): bool
    {
        return $user->hasAnyPermission(['incoming.delete']);
    }

    public function receive(User $user, IncomingArrival $arrival): bool
    {
        return $user->hasAnyPermission(['receive.create', 'incoming.update']);
    }
}