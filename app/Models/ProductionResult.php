<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductionResult extends Model
{
    protected $fillable = [
        'work_order_id', 'parent_part_id', 'process_id', 'machine_id',
        'result_date', 'shift', 'qty_good', 'qty_reject', 'uom',
        'reported_by', 'notes', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'result_date' => 'date',
        'qty_good' => 'float',
        'qty_reject' => 'float',
    ];

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function parentPart(): BelongsTo
    {
        return $this->belongsTo(Part::class, 'parent_part_id');
    }

    public function process(): BelongsTo
    {
        return $this->belongsTo(Process::class);
    }

    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }
}
