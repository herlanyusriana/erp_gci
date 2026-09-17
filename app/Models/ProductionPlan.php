<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductionPlan extends Model
{
    protected $fillable = ['plan_date', 'notes', 'created_by', 'updated_by'];

    protected $casts = [
        'plan_date' => 'date',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(ProductionPlanItem::class);
    }
}
