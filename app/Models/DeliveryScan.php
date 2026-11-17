<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeliveryScan extends Model
{
    public const TYPE_TROLLEY = 'trolley';

    public const TYPE_TAG = 'tag';

    protected $fillable = [
        'delivery_id', 'scan_type', 'code', 'part_stock_id', 'qty', 'deducted_qty', 'origin_location_code',
        'qc_status', 'qc_notes', 'qc_checked_at', 'qc_checked_by',
        'scanned_by', 'scanned_at',
    ];

    protected $casts = [
        'qty' => 'float',
        'deducted_qty' => 'float',
        'scanned_at' => 'datetime',
        'qc_checked_at' => 'datetime',
    ];

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class);
    }

    public function partStock(): BelongsTo
    {
        return $this->belongsTo(PartStock::class);
    }

    public function scanner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'scanned_by');
    }
}
