<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeliveryItem extends Model
{
    protected $fillable = [
        'delivery_id', 'part_stock_id', 'part_id', 'tag', 'qty', 'qty_unit',
        'origin_location_code', 'price', 'total_price', 'notes',
    ];

    protected $casts = [
        'qty' => 'float',
        'price' => 'float',
        'total_price' => 'float',
    ];

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class);
    }

    public function partStock(): BelongsTo
    {
        return $this->belongsTo(PartStock::class);
    }

    public function part(): BelongsTo
    {
        return $this->belongsTo(Part::class);
    }
}
