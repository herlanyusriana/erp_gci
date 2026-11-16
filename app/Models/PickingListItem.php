<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu baris rencana picking: customer + FG + qty, tanpa tag.
 *
 * `stock_status` adalah snapshot hasil pengecekan stok saat picking dibuat:
 * `ok`, `short_in_production`, `short_no_production`, atau `no_data`.
 */
class PickingListItem extends Model
{
    public const STOCK_OK = 'ok';

    public const STOCK_SHORT_IN_PRODUCTION = 'short_in_production';

    public const STOCK_SHORT_NO_PRODUCTION = 'short_no_production';

    public const STOCK_NO_DATA = 'no_data';

    public const STOCK_STATUSES = [
        self::STOCK_OK,
        self::STOCK_SHORT_IN_PRODUCTION,
        self::STOCK_SHORT_NO_PRODUCTION,
        self::STOCK_NO_DATA,
    ];

    protected $fillable = [
        'picking_list_id', 'sequence', 'customer_id', 'part_id',
        'qty', 'qty_unit', 'stock_available', 'stock_shortage', 'stock_status', 'notes',
    ];

    protected $casts = [
        'sequence' => 'integer',
        'qty' => 'float',
        'stock_available' => 'float',
        'stock_shortage' => 'float',
    ];

    public function pickingList(): BelongsTo
    {
        return $this->belongsTo(PickingList::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function part(): BelongsTo
    {
        return $this->belongsTo(Part::class);
    }

    public function isShort(): bool
    {
        return $this->stock_status !== self::STOCK_OK;
    }
}
