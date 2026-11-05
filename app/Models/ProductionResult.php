<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductionResult extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'work_order_id', 'parent_part_id', 'process_id', 'machine_id',
        'result_date', 'shift', 'qty_good', 'qty_reject', 'uom',
        'period_start', 'period_end', 'is_backfilled', 'label_status',
        'label_carried_over_qty', 'label_carry_over_source_id',
        'reported_by', 'notes', 'created_by', 'updated_by',
        'output_part_stock_id', 'reversed_by',
    ];

    protected $casts = [
        'result_date' => 'date',
        'qty_good' => 'float',
        'qty_reject' => 'float',
        'label_carried_over_qty' => 'float',
        'is_backfilled' => 'boolean',
    ];

    public const LABEL_PENDING = 'pending';

    public const LABEL_PRINTED = 'printed';

    public const LABEL_DELIVERED = 'delivered';

    public const LABEL_NONE = 'none';

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

    public function carryOverSource(): BelongsTo
    {
        return $this->belongsTo(self::class, 'label_carry_over_source_id');
    }
}
