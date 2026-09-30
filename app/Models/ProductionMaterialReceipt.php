<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductionMaterialReceipt extends Model
{
    protected $fillable = [
        'material_issue_item_id', 'tag', 'part_id', 'machine_id',
        'received_by', 'received_at', 'notes',
    ];

    protected $casts = [
        'received_at' => 'datetime',
    ];

    public $timestamps = true;

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