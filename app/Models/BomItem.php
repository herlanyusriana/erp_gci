<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BomItem extends Model
{
    public const OUTPUT_NORMAL = 'normal';

    public const OUTPUT_SHARED = 'shared';

    public const OUTPUT_BYPRODUCT = 'byproduct';

    public const OUTPUT_TYPES = [
        self::OUTPUT_NORMAL,
        self::OUTPUT_SHARED,
        self::OUTPUT_BYPRODUCT,
    ];

    protected $fillable = [
        'bom_id', 'sequence', 'process_id', 'machine_id',
        'parent_part_id', 'parent_part_name', 'parent_qty', 'parent_uom',
        'child_part_id', 'child_part_name', 'size', 'child_qty',
        'uom_rm', 'special_code', 'source', 'output_type', 'is_active',
        'created_by', 'updated_by',
    ];

    protected $casts = [
        'parent_qty' => 'float',
        'child_qty' => 'float',
        'is_active' => 'boolean',
    ];

    public function bom(): BelongsTo
    {
        return $this->belongsTo(Bom::class);
    }

    public function process(): BelongsTo
    {
        return $this->belongsTo(Process::class);
    }

    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }

    public function parentPart(): BelongsTo
    {
        return $this->belongsTo(Part::class, 'parent_part_id');
    }

    public function childPart(): BelongsTo
    {
        return $this->belongsTo(Part::class, 'child_part_id');
    }
}
