<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PurchaseOrder extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'po_no', 'supplier_id', 'status', 'po_date', 'expected_date',
        'notes', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'po_date' => 'date',
        'expected_date' => 'date',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    public function arrivals(): HasMany
    {
        return $this->hasMany(IncomingArrival::class);
    }
}