<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class IncomingReceive extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'arrival_item_id', 'part_id',
        'tag', 'qty', 'qty_unit',
        'bundle_qty', 'bundle_unit',
        'weight', 'net_weight', 'gross_weight', 'weight_kgm',
        'location_code', 'qc_status',
        'invoice_no', 'delivery_note_no', 'truck_no', 'jo_po_number',
        'ata_date', 'qc_audited_at', 'qc_audited_by',
    ];

    protected $casts = [
        'qty' => 'float',
        'bundle_qty' => 'float',
        'weight' => 'float',
        'net_weight' => 'float',
        'gross_weight' => 'float',
        'weight_kgm' => 'float',
        'ata_date' => 'datetime',
        'qc_audited_at' => 'datetime',
    ];

    public function arrivalItem(): BelongsTo
    {
        return $this->belongsTo(IncomingArrivalItem::class, 'arrival_item_id');
    }

    public function part(): BelongsTo
    {
        return $this->belongsTo(Part::class);
    }
}