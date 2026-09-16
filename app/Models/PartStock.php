<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class PartStock extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'part_id', 'tag', 'qty', 'received_at', 'receive_id', 'qty_unit', 'price', 'remarks',
    ];

    protected $casts = [
        'qty' => 'float',
        'price' => 'float',
        'received_at' => 'datetime',
    ];

    public function part(): BelongsTo
    {
        return $this->belongsTo(Part::class);
    }

    public function receive(): BelongsTo
    {
        return $this->belongsTo(IncomingReceive::class, 'receive_id');
    }
}