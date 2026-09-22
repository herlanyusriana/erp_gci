<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Price master: harga sebuah part dari seorang supplier, berlaku sejak tanggal.
 */
class PartPrice extends Model
{
    protected $fillable = [
        'supplier_id', 'part_id', 'price', 'currency', 'valid_from',
        'is_active', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'price' => 'float',
        'valid_from' => 'date',
        'is_active' => 'boolean',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function part(): BelongsTo
    {
        return $this->belongsTo(Part::class);
    }
}
