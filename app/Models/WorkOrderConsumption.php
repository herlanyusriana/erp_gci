<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkOrderConsumption extends Model
{
    protected $fillable = [
        'work_order_id', 'work_order_item_id', 'part_stock_id', 'part_id',
        'qty', 'uom',
    ];

    protected $casts = [
        'qty' => 'float',
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
}