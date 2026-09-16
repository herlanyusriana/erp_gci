<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Part extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'part_number', 'part_name', 'hs_code', 'part_type_id', 'model', 'uom_id',
        'size', 'nett_weight', 'is_active', 'remarks', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'nett_weight' => 'float',
        'is_active' => 'boolean',
    ];

    public function partType(): BelongsTo
    {
        return $this->belongsTo(PartType::class);
    }

    public function uom(): BelongsTo
    {
        return $this->belongsTo(Uom::class);
    }

    public function partSubstitutes(): HasMany
    {
        return $this->hasMany(PartSubstitute::class, 'part_id');
    }

    public function substituteOf(): HasMany
    {
        return $this->hasMany(PartSubstitute::class, 'substitute_part_id');
    }

    public function workOrders(): HasMany
    {
        return $this->hasMany(WorkOrder::class);
    }
}