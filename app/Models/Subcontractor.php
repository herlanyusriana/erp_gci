<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Subcontractor extends Model
{
    use SoftDeletes;

    /** Fasilitas BC yang dipakai pada data kepabeanan. */
    public const BC_KB = 'KB';

    public const BC_NON_KB = 'NON-KB';

    public const BC_FACILITIES = [self::BC_KB, self::BC_NON_KB];

    protected $fillable = [
        'subcontractor_code', 'subcontractor_name', 'address', 'phone',
        'email', 'contact_person', 'npwp', 'bc_facility', 'process_notes',
        'is_active', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function incomingArrivals(): HasMany
    {
        return $this->hasMany(IncomingArrival::class, 'subcontractor_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
