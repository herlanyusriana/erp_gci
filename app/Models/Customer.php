<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    use SoftDeletes;

    /** Fasilitas BC yang dipakai pada data kepabeanan. */
    public const BC_KB = 'KB';

    public const BC_NON_KB = 'NON-KB';

    public const BC_FACILITIES = [self::BC_KB, self::BC_NON_KB];

    protected $fillable = [
        'customer_code', 'customer_name', 'short_name', 'address', 'phone',
        'contact_person', 'email', 'npwp', 'bc_facility',
        'is_active', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function deliveries()
    {
        return $this->hasMany(Delivery::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
