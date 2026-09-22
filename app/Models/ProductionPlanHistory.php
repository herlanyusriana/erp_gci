<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductionPlanHistory extends Model
{
    protected $fillable = [
        'production_plan_id', 'production_plan_item_id', 'event', 'before', 'after', 'user_id',
    ];

    protected $casts = [
        'before' => 'array',
        'after' => 'array',
    ];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(ProductionPlan::class, 'production_plan_id');
    }

    public function planItem(): BelongsTo
    {
        return $this->belongsTo(ProductionPlanItem::class, 'production_plan_item_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
