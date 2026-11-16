<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IncomingReceivePacking extends Model
{
    protected $fillable = [
        'receive_id',
        'packing_no',
        'sequence',
        'qty',
        'uom',
        'net_weight',
        'gross_weight',
    ];

    protected $casts = [
        'sequence' => 'integer',
        'qty' => 'float',
        'net_weight' => 'float',
        'gross_weight' => 'float',
    ];

    public function receive(): BelongsTo
    {
        return $this->belongsTo(IncomingReceive::class, 'receive_id');
    }
}
