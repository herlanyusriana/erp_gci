<?php

namespace App\Policies;

use App\Models\MaterialIssue;
use App\Models\User;

class MaterialIssuePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('stock.issue');
    }

    public function view(User $user, MaterialIssue $materialIssue): bool
    {
        return $this->viewAny($user);
    }
}
