<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cycle time (tact time) sebuah mesin untuk satu part — detik per pcs.
 */
class MachineCycleTime extends Model
{
    protected $fillable = [
        'machine_id', 'part_id', 'cycle_time_seconds', 'is_active',
        'created_by', 'updated_by',
    ];

    protected $casts = [
        'cycle_time_seconds' => 'float',
        'is_active' => 'boolean',
    ];

    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }

    public function part(): BelongsTo
    {
        return $this->belongsTo(Part::class);
    }
}
