<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PartType extends Model
{
    protected $fillable = ['code', 'name'];

    public function parts(): HasMany
    {
        return $this->hasMany(Part::class);
    }
}