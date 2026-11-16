<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Rencana picking harian.
 *
 * Satu picking list mencakup satu tanggal dan boleh memuat beberapa customer
 * pada baris berbeda. Tag FG belum ditentukan di sini — operator memilih tag
 * saat memindai barang di APK.
 */
class PickingList extends Model
{
    use SoftDeletes;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PICKED = 'picked';

    public const STATUSES = [self::STATUS_DRAFT, self::STATUS_PICKED];

    protected $fillable = [
        'picking_no', 'picking_date', 'status', 'notes', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'picking_date' => 'date',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(PickingListItem::class);
    }

    /** Pengiriman per customer yang lahir dari picking list ini. */
    public function deliveries(): HasMany
    {
        return $this->hasMany(Delivery::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Nomor picking: PK-YYYYMM-#### (withTrashed agar nomor tidak dipakai ulang). */
    public static function generatePickingNo(?\DateTimeInterface $date = null): string
    {
        $date = $date ?? now();
        $prefix = 'PK-'.$date->format('Ym').'-';

        $last = static::withTrashed()
            ->where('picking_no', 'like', $prefix.'%')
            ->orderByDesc('picking_no')
            ->value('picking_no');

        $next = $last !== null ? ((int) substr((string) $last, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }
}
