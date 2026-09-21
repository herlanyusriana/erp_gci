<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Booking material untuk sebuah WO.
 *
 * Dibuat saat WO di-release (stok fisik BELUM berkurang), berubah jadi
 * `consumed` saat Production Result, dan `released` saat WO dibatalkan.
 */
class WorkOrderMaterialBooking extends Model
{
    public const STATUS_BOOKED = 'booked';

    public const STATUS_CONSUMED = 'consumed';

    public const STATUS_RELEASED = 'released';

    protected $fillable = [
        'work_order_id', 'work_order_item_id', 'part_id', 'part_stock_id',
        'tag', 'qty', 'uom', 'status', 'booked_at', 'consumed_at',
        'created_by', 'updated_by',
    ];

    protected $casts = [
        'qty' => 'float',
        'booked_at' => 'datetime',
        'consumed_at' => 'datetime',
    ];

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function workOrderItem(): BelongsTo
    {
        return $this->belongsTo(WorkOrderItem::class);
    }

    public function part(): BelongsTo
    {
        return $this->belongsTo(Part::class);
    }

    public function partStock(): BelongsTo
    {
        return $this->belongsTo(PartStock::class);
    }
}
