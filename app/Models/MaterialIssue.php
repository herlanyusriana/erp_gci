<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MaterialIssue extends Model
{
    protected $fillable = [
        'issue_no', 'work_order_id', 'issue_date', 'issued_by', 'received_by',
        'status', 'idempotency_key', 'notes', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'issue_date' => 'date',
    ];

    public const STATUSES = ['posted', 'cancelled'];

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(MaterialIssueItem::class);
    }

    public static function generateIssueNo(): string
    {
        $prefix = 'MI-'.now()->format('ym').'-';
        $last = static::query()
            ->where('issue_no', 'like', $prefix.'%')
            ->orderByDesc('issue_no')
            ->value('issue_no');
        $seq = $last ? ((int) substr($last, strlen($prefix)) + 1) : 1;

        return $prefix.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }
}
