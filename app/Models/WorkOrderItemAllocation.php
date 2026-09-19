<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkOrderItemAllocation extends Model
{
    protected $fillable = [
        'work_order_item_id', 'part_id', 'qty', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'qty' => 'float',
    ];

    public function workOrderItem(): BelongsTo
    {
        return $this->belongsTo(WorkOrderItem::class);
    }

    public function part(): BelongsTo
    {
        return $this->belongsTo(Part::class);
    }
}
