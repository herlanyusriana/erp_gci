<?php

namespace App\Policies;

use App\Models\MaterialIssue;
use App\Models\User;

class MaterialIssuePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyPermission(['stock.view', 'incoming.view', 'receive.view', 'work_order.view']);
    }

    public function view(User $user, MaterialIssue $materialIssue): bool
    {
        return $this->viewAny($user);
    }
}
