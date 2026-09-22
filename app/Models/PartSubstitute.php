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

    /**
     * Daftar part per supplier (sumber tunggal part↔supplier), untuk dropdown
     * part yang difilter berdasarkan supplier terpilih.
     *
     * @return list<array{supplier_id:int, part_id:int, part_number:string|null, part_name:string|null, uom:string|null}>
     */
    public static function supplierParts(): array
    {
        return static::query()
            ->where('is_active', true)
            ->whereNotNull('supplier_id')
            ->with(['substitutePart:id,part_number,part_name,uom_id', 'substitutePart.uom:id,code'])
            ->get()
            ->map(fn (self $s) => [
                'supplier_id' => (int) $s->supplier_id,
                'part_id' => (int) $s->substitute_part_id,
                'part_number' => $s->substitutePart?->part_number,
                'part_name' => $s->substitutePart?->part_name,
                'uom' => $s->substitutePart?->uom?->code,
            ])
            ->unique(fn (array $r) => $r['supplier_id'].'|'.$r['part_id'])
            ->values()
            ->all();
    }
}
