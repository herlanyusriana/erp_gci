<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Machine extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'machine_code', 'machine_name', 'sequence', 'is_active', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function processes(): BelongsToMany
    {
        return $this->belongsToMany(Process::class, 'machine_process');
    }
}
