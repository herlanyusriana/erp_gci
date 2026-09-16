<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class TruckingCompany extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'company_code', 'company_name', 'address', 'phone', 'email',
        'contact_person', 'is_active', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function arrivals(): HasMany
    {
        return $this->hasMany(IncomingArrival::class, 'trucking_company_id');
    }
}
