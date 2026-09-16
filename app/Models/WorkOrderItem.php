<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkOrderItem extends Model
{
    protected $fillable = [
        'work_order_id', 'sequence', 'process_id', 'machine_id',
        'parent_part_id', 'parent_part_name', 'parent_qty', 'parent_uom',
        'child_part_id', 'child_part_name', 'size', 'child_qty', 'uom_rm',
        'special_code', 'source',
        'qty_required', 'qty_consumed',
        'created_by', 'updated_by',
    ];

    protected $casts = [
        'parent_qty' => 'float',
        'child_qty' => 'float',
        'qty_required' => 'float',
        'qty_consumed' => 'float',
    ];

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function process(): BelongsTo
    {
        return $this->belongsTo(Process::class);
    }

    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }

    public function parentPart(): BelongsTo
    {
        return $this->belongsTo(Part::class, 'parent_part_id');
    }

    public function childPart(): BelongsTo
    {
        return $this->belongsTo(Part::class, 'child_part_id');
    }
}