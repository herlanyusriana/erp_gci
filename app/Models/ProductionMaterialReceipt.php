<?php

namespace App\Models;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class ProductionMaterialReceipt extends Model
{
    protected $fillable = [
        'material_issue_item_id', 'tag', 'part_id', 'machine_id',
        'received_by', 'received_at', 'notes',
        'transfer_status', 'work_order_id', 'work_order_item_id', 'part_stock_id',
        'qty', 'qty_consumed', 'uom', 'invoice', 'supplier',
    ];

    protected $casts = [
        'received_at' => 'datetime',
        'qty' => 'float',
        'qty_consumed' => 'float',
    ];

    public $timestamps = true;

    public function assertValidBalance(): void
    {
        try {
            $qty = BigDecimal::of($this->getRawOriginal('qty') ?? -1);
            $consumed = BigDecimal::of($this->getRawOriginal('qty_consumed') ?? -1);
            $valid = $this->transfer_status === 'transferred' && ! $qty->isNegative()
                && ! $consumed->isNegative() && $consumed->isLessThanOrEqualTo($qty);
        } catch (MathException) {
            $valid = false;
        }
        if (! $valid) {
            throw ValidationException::withMessages(['qty_good' => __('Saldo konsumsi tidak valid. Rekonsiliasi diperlukan.')]);
        }
    }

    public function materialIssueItem(): BelongsTo
    {
        return $this->belongsTo(MaterialIssueItem::class);
    }

    public function part(): BelongsTo
    {
        return $this->belongsTo(Part::class);
    }

    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }
}
