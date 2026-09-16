<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PartSubstitute extends Model
{
    protected $fillable = [
        'part_id', 'substitute_part_id', 'supplier_id',
        'material_group', 'source', 'is_active', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function part(): BelongsTo
    {
        return $this->belongsTo(Part::class, 'part_id');
    }

    public function substitutePart(): BelongsTo
    {
        return $this->belongsTo(Part::class, 'substitute_part_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }
}