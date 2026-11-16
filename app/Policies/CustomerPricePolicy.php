<?php

namespace App\Policies;

use App\Models\CustomerPrice;
use App\Models\User;

/**
 * Harga jual = data komersial. Semua yang boleh melihat pengiriman boleh
 * melihat harga (dipakai di invoice), tetapi hanya role dengan permission
 * `customer_price.*` yang boleh mengubahnya.
 */
class CustomerPricePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyPermission(['customer_price.view', 'delivery.view']);
    }

    public function view(User $user, CustomerPrice $customerPrice): bool
    {
        return $user->hasAnyPermission(['customer_price.view', 'delivery.view']);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyPermission(['customer_price.create']);
    }

    public function update(User $user, CustomerPrice $customerPrice): bool
    {
        return $user->hasAnyPermission(['customer_price.update']);
    }

    public function delete(User $user, CustomerPrice $customerPrice): bool
    {
        return $user->hasAnyPermission(['customer_price.delete']);
    }
}
