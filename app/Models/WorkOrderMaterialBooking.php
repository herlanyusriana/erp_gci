<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkOrderMaterialBooking extends Model
{
    public const STATUS_BOOKED = 'booked';

    public const STATUS_CONSUMED = 'consumed';

    public const STATUS_TRANSFERRED = 'transferred';

    public const STATUS_RELEASED = 'released';

    protected $fillable = [
        'work_order_id', 'work_order_item_id', 'part_id', 'part_stock_id',
        'tag', 'qty', 'uom', 'status', 'booked_at', 'consumed_at',
        'created_by', 'updated_by', 'transferred_at',
    ];

    protected $casts = [
        'qty' => 'float',
        'booked_at' => 'datetime',
        'consumed_at' => 'datetime',
        'transferred_at' => 'datetime',
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
