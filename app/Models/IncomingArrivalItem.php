<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class IncomingArrivalItem extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'arrival_id', 'part_id',
        'material_group', 'size',
        'qty_goods', 'unit_goods', 'qty_bundle', 'unit_bundle',
        'weight_nett', 'unit_weight', 'weight_gross',
        'price', 'total_price',
        'is_foc', 'notes',
    ];

    protected $casts = [
        'qty_goods' => 'float',
        'qty_bundle' => 'float',
        'weight_nett' => 'float',
        'weight_gross' => 'float',
        'price' => 'float',
        'total_price' => 'float',
        'is_foc' => 'boolean',
    ];

    public function arrival(): BelongsTo
    {
        return $this->belongsTo(IncomingArrival::class, 'arrival_id');
    }

    public function part(): BelongsTo
    {
        return $this->belongsTo(Part::class);
    }

    /**
     * Display name used on invoice/packing documents (mirrors reference arrivals invoice).
     */
    public function getDisplayPartNameAttribute(): string
    {
        return (string) ($this->part?->part_name ?: $this->part?->part_number ?: $this->material_group);
    }

    public function receives(): HasMany
    {
        return $this->hasMany(IncomingReceive::class, 'arrival_item_id');
    }
}