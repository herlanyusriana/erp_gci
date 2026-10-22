<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Bom extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'part_id', 'bom_no', 'version', 'is_active', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function part(): BelongsTo
    {
        return $this->belongsTo(Part::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(BomItem::class)->orderBy('sequence');
    }

    /**
     * Nomor BOM berurutan mulai dari 1. withTrashed(): nomor baris terhapus tidak
     * dipakai ulang.
     */
    public static function generateBomNo(): int
    {
        return (int) static::withTrashed()->max('bom_no') + 1;
    }
}
