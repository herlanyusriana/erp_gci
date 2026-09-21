<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class WorkOrder extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'wo_no', 'part_id', 'qty', 'status',
        'planned_date', 'released_at', 'completed_at',
        'remarks', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'qty' => 'float',
        'planned_date' => 'date',
        'released_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public const STATUSES = ['planned', 'in_progress', 'completed', 'cancelled'];

    public function part(): BelongsTo
    {
        return $this->belongsTo(Part::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(WorkOrderItem::class)->orderBy('sequence');
    }

    public function consumptions(): HasMany
    {
        return $this->hasMany(WorkOrderConsumption::class);
    }

    /** Baris Production Plan yang memuat WO ini. */
    public function planItems(): HasMany
    {
        return $this->hasMany(ProductionPlanItem::class);
    }

    /** Hasil produksi per step. */
    public function results(): HasMany
    {
        return $this->hasMany(ProductionResult::class);
    }

    public static function generateWoNo(): string
    {
        $prefix = 'WO-'.now()->format('ym');
        $last = static::query()
            ->where('wo_no', 'like', $prefix.'%')
            ->orderByDesc('wo_no')
            ->value('wo_no');
        $seq = $last ? ((int) substr($last, strlen($prefix)) + 1) : 1;

        return $prefix.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }
}
