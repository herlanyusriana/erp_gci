<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Delivery extends Model
{
    use SoftDeletes;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PICKED = 'picked';

    public const STATUS_SHIPPED = 'shipped';

    public const STATUSES = [self::STATUS_DRAFT, self::STATUS_PICKED, self::STATUS_SHIPPED];

    protected $fillable = [
        'delivery_no', 'customer_id', 'delivery_date', 'status', 'invoice_no',
        'truck_no', 'driver_name', 'notes', 'picked_at', 'shipped_at',
        'created_by', 'updated_by',
    ];

    protected $casts = [
        'delivery_date' => 'date',
        'picked_at' => 'datetime',
        'shipped_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(DeliveryItem::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isPicked(): bool
    {
        return $this->status === self::STATUS_PICKED;
    }

    public function isShipped(): bool
    {
        return $this->status === self::STATUS_SHIPPED;
    }

    /**
     * Nomor DO: DO-YYYYMM-#### . Memakai withTrashed() supaya nomor dokumen
     * yang dihapus tidak terpakai ulang (kolomnya unik).
     */
    public static function generateDeliveryNo(?\DateTimeInterface $date = null): string
    {
        $date = $date ?? now();
        $prefix = 'DO-'.$date->format('Ym').'-';

        $last = static::withTrashed()
            ->where('delivery_no', 'like', $prefix.'%')
            ->orderByDesc('delivery_no')
            ->value('delivery_no');

        $next = $last !== null ? ((int) substr((string) $last, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }
}
