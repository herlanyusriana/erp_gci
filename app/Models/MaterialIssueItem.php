<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaterialIssueItem extends Model
{
    protected $fillable = [
        'material_issue_id', 'work_order_item_id', 'part_id',
        'part_stock_id', 'tag', 'invoice', 'supplier', 'qty', 'uom', 'price',
    ];

    protected $casts = [
        'qty' => 'float',
        'price' => 'float',
    ];

    public function materialIssue(): BelongsTo
    {
        return $this->belongsTo(MaterialIssue::class);
    }

    public function workOrderItem(): BelongsTo
    {
        return $this->belongsTo(WorkOrderItem::class);
    }

    public function part(): BelongsTo
    {
        return $this->belongsTo(Part::class);
    }
}
