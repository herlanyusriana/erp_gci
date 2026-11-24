<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductionPlanMachineDay extends Model
{
    protected $fillable = [
        'production_plan_id',
        'machine_id',
        'initial_qdc',
    ];

    protected $casts = [
        'initial_qdc' => 'boolean',
    ];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(ProductionPlan::class, 'production_plan_id');
    }

    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }
}
