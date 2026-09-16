<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Supplier extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'supplier_code', 'supplier_name', 'signature_path', 'is_active', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected $appends = ['signature_url'];

    /**
     * URL publik gambar ttd (storage/app/public + symlink, tanpa leading slash).
     */
    public function getSignatureUrlAttribute(): ?string
    {
        return $this->signature_path ? \Illuminate\Support\Facades\Storage::disk('public')->url($this->signature_path) : null;
    }

    public function partSubstitutes(): BelongsTo
    {
        return $this->belongsTo(PartSubstitute::class);
    }
}