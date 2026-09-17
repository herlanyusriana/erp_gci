<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductionPlanItem extends Model
{
    protected $fillable = [
        'production_plan_id', 'machine_id', 'work_order_id',
        'fg_part_id', 'wip_part_id', 'sequence',
        'target_d', 'target_d1', 'target_d2',
        'created_by', 'updated_by',
    ];

    protected $casts = [
        'target_d' => 'float',
        'target_d1' => 'float',
        'target_d2' => 'float',
    ];

    protected $appends = ['avail_qty'];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(ProductionPlan::class, 'production_plan_id');
    }

    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function fgPart(): BelongsTo
    {
        return $this->belongsTo(Part::class, 'fg_part_id');
    }

    public function wipPart(): BelongsTo
    {
        return $this->belongsTo(Part::class, 'wip_part_id');
    }

    public function getAvailQtyAttribute(): float
    {
        $woQty = (float) ($this->workOrder?->qty ?? 0);
        $allocated = (float) $this->target_d + (float) $this->target_d1 + (float) $this->target_d2;

        return round($woQty - $allocated, 4);
    }
}
