<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WorkOrder;

class WorkOrderPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyPermission(['work_order.view', 'production.view']);
    }

    public function view(User $user, WorkOrder $workOrder): bool
    {
        return $user->hasAnyPermission(['work_order.view', 'production.view']);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyPermission(['work_order.create', 'production.update']);
    }

    public function release(User $user, WorkOrder $workOrder): bool
    {
        return $user->hasAnyPermission(['work_order.update', 'production.update']);
    }

    /**
     * Issue material ke produksi lewat scan label (mobile).
     */
    public function issue(User $user, WorkOrder $workOrder): bool
    {
        return $user->hasAnyPermission(['stock.issue', 'work_order.update', 'production.update']);
    }

    public function update(User $user, WorkOrder $workOrder): bool
    {
        return $user->hasAnyPermission(['work_order.update']);
    }

    public function delete(User $user, WorkOrder $workOrder): bool
    {
        return $user->hasAnyPermission(['work_order.delete']);
    }
}
