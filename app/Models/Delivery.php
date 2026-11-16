<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Delivery extends Model
{
    use SoftDeletes;

    /** Delivery Order: picking qty sudah ditetapkan. */
    public const STATUS_DRAFT = 'draft';

    /** Barang sudah dipindah ke area pengiriman. */
    public const STATUS_PICKED = 'picked';

    /** QC menyatakan barang lolos. */
    public const STATUS_QC_PASSED = 'qc_passed';

    /** Outgoing disubmit: barang keluar dan stok berkurang. */
    public const STATUS_RELEASED = 'released';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_PICKED,
        self::STATUS_QC_PASSED,
        self::STATUS_RELEASED,
    ];

    public const QC_PENDING = 'pending';

    public const QC_PASSED = 'passed';

    public const QC_REJECTED = 'rejected';

    protected $fillable = [
        'delivery_no', 'customer_id', 'delivery_date', 'status', 'invoice_no', 'pen_no', 'aju_no',
        'qc_status', 'qc_notes', 'qc_checked_at', 'qc_checked_by',
        'truck_no', 'driver_name', 'notes',
        'picked_at', 'released_at', 'shipped_at',
        'created_by', 'updated_by',
    ];

    protected $casts = [
        'delivery_date' => 'date',
        'picked_at' => 'datetime',
        'released_at' => 'datetime',
        'qc_checked_at' => 'datetime',
        'shipped_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(DeliveryItem::class);
    }

    public function scans(): HasMany
    {
        return $this->hasMany(DeliveryScan::class);
    }

    /** Jumlah troli yang sudah dipindai pada tahap loading. */
    public function scannedTrolleyCount(): int
    {
        return $this->scans()->where('scan_type', DeliveryScan::TYPE_TROLLEY)->count();
    }

    /** Tag pengiriman yang sudah dipindai. */
    public function scannedTagCount(): int
    {
        return $this->scans()->where('scan_type', DeliveryScan::TYPE_TAG)->count();
    }

    /** Apakah seluruh tag pada dokumen sudah dipindai? */
    public function allTagsScanned(): bool
    {
        $total = $this->items()->count();

        return $total > 0 && $this->scannedTagCount() >= $total;
    }

    /** Loading sah bila ada troli dan semua tag sudah discan. */
    public function loadingComplete(): bool
    {
        return $this->scannedTrolleyCount() > 0 && $this->allTagsScanned();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function qcInspector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'qc_checked_by');
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isPicked(): bool
    {
        return $this->status === self::STATUS_PICKED;
    }

    public function isQcPassed(): bool
    {
        return $this->status === self::STATUS_QC_PASSED;
    }

    public function isReleased(): bool
    {
        return $this->status === self::STATUS_RELEASED;
    }

    /** Dokumen (SJ, invoice, packing list) hanya boleh dicetak setelah submit. */
    public function documentsReleased(): bool
    {
        return $this->isReleased();
    }

    /** Label pengiriman boleh dicetak setelah barang ada di area pengiriman. */
    public function labelPrintable(): bool
    {
        return $this->status !== self::STATUS_DRAFT;
    }

    /**
     * Nomor DO: DO-YYYYMM-#### . Memakai withTrashed() supaya nomor dokumen
     * yang dihapus tidak terpakai ulang (kolomnya unik).
     */
    public static function generateDeliveryNo(?\DateTimeInterface $date = null): string
    {
        $date = $date ?? now();
        $prefix = 'DO-'.$date->format('Ym').'-';

        $last = static::withTrashed()
            ->where('delivery_no', 'like', $prefix.'%')
            ->orderByDesc('delivery_no')
            ->value('delivery_no');

        $next = $last !== null ? ((int) substr((string) $last, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }
}
