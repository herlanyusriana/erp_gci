<?php

namespace App\Policies;

use App\Models\ProductionPlan;
use App\Models\User;

class ProductionPlanPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyPermission(['production_plan.view', 'production.view']);
    }

    public function view(User $user, ProductionPlan $plan): bool
    {
        return $user->hasAnyPermission(['production_plan.view', 'production.view']);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyPermission(['production_plan.create', 'production.update']);
    }

    public function update(User $user, ProductionPlan $plan): bool
    {
        return $user->hasAnyPermission(['production_plan.update', 'production.update']);
    }

    public function reorder(User $user): bool
    {
        return $user->hasAnyPermission(['production_plan.update', 'production.update']);
    }

    public function delete(User $user, ProductionPlan $plan): bool
    {
        return $user->hasAnyPermission(['production_plan.delete', 'production.update']);
    }
}
