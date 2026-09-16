<?php

namespace App\Policies;

use App\Models\PartStock;
use App\Models\User;

class PartStockPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyPermission(['stock.view', 'incoming.view', 'receive.view']);
    }

    public function view(User $user, PartStock $stock): bool
    {
        return $user->hasAnyPermission(['stock.view', 'incoming.view', 'receive.view']);
    }
}