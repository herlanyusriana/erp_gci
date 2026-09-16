<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class IncomingArrivalContainer extends Model
{
    use SoftDeletes;

    protected $fillable = ['arrival_id', 'container_no', 'seal_code', 'size'];

    public function arrival(): BelongsTo
    {
        return $this->belongsTo(IncomingArrival::class, 'arrival_id');
    }

    public function inspection(): HasOne
    {
        return $this->hasOne(IncomingArrivalContainerInspection::class, 'arrival_container_id');
    }
}