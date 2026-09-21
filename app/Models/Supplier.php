<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class Supplier extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'supplier_code', 'supplier_name', 'address', 'phone', 'email', 'contact_person',
        'bank_account', 'signature_path', 'is_active', 'created_by', 'updated_by',
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
        return $this->signature_path ? Storage::disk('public')->url($this->signature_path) : null;
    }

    public function partSubstitutes(): HasMany
    {
        return $this->hasMany(PartSubstitute::class);
    }
}
