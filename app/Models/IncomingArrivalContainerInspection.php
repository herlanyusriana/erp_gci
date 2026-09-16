<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class IncomingArrivalContainerInspection extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'arrival_container_id', 'status', 'seal_condition', 'container_condition',
        'photo_front', 'photo_back', 'photo_left', 'photo_right', 'photo_inside', 'photo_seal',
        'driver_name', 'notes', 'inspected_by', 'inspected_at',
    ];

    protected $casts = [
        'inspected_at' => 'datetime',
    ];

    public function container(): BelongsTo
    {
        return $this->belongsTo(IncomingArrivalContainer::class, 'arrival_container_id');
    }
}