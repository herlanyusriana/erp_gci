<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductionPlanItem extends Model
{
    protected $fillable = [
        'production_plan_id', 'machine_id', 'process_id', 'work_order_id',
        'fg_part_id', 'input_part_id', 'wip_part_id', 'sequence', 'sequence_d', 'sequence_d1', 'sequence_d2', 'step_sequence',
        'target_d', 'target_d1', 'target_d2',
        'created_by', 'updated_by',
    ];

    protected $casts = [
        'sequence_d' => 'integer',
        'sequence_d1' => 'integer',
        'sequence_d2' => 'integer',
        'target_d' => 'float',
        'target_d1' => 'float',
        'target_d2' => 'float',
    ];

    protected $appends = ['avail_qty'];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(ProductionPlan::class, 'production_plan_id');
    }

    public function process(): BelongsTo
    {
        return $this->belongsTo(Process::class);
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

    /** Material yang masuk ke mesin (child step pertama di grup mesin ini). */
    public function inputPart(): BelongsTo
    {
        return $this->belongsTo(Part::class, 'input_part_id');
    }

    /** Hasil yang keluar dari mesin (parent step terakhir di grup mesin ini). */
    public function wipPart(): BelongsTo
    {
        return $this->belongsTo(Part::class, 'wip_part_id');
    }

    public function getAvailQtyAttribute(): float
    {
        if (array_key_exists('remaining_qty', $this->attributes)) {
            return round((float) $this->attributes['remaining_qty'], 4);
        }

        $woQty = (float) ($this->workOrder?->qty ?? 0);

        return round($woQty, 4);
    }
}
