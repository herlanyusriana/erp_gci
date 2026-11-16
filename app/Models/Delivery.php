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
        'delivery_no', 'customer_id', 'picking_list_id', 'delivery_date', 'status', 'invoice_no', 'pen_no', 'aju_no',
        'ceisa_released_at', 'ceisa_released_by',
        'qc_status', 'qc_notes', 'qc_checked_at', 'qc_checked_by',
        'truck_no', 'driver_name', 'notes',
        'picked_at', 'stock_deducted_at', 'released_at', 'shipped_at',
        'created_by', 'updated_by',
    ];

    protected $casts = [
        'delivery_date' => 'date',
        'picked_at' => 'datetime',
        'stock_deducted_at' => 'datetime',
        'ceisa_released_at' => 'datetime',
        'released_at' => 'datetime',
        'qc_checked_at' => 'datetime',
        'shipped_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** Picking list asal pengiriman (null untuk delivery yang dibuat manual). */
    public function pickingList(): BelongsTo
    {
        return $this->belongsTo(PickingList::class);
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

    /** Qty rencana per part (satu part bisa muncul di beberapa baris). */
    public function plannedQtyByPart(): array
    {
        return $this->items()
            ->selectRaw('part_id, SUM(qty) AS total')
            ->groupBy('part_id')
            ->pluck('total', 'part_id')
            ->map(fn ($value): float => (float) $value)
            ->all();
    }

    /** Qty tag yang sudah dipindai, dikelompokkan per part. */
    public function scannedQtyByPart(): array
    {
        return DeliveryScan::query()
            ->join('part_stocks', 'part_stocks.id', '=', 'delivery_scans.part_stock_id')
            ->where('delivery_scans.delivery_id', $this->id)
            ->where('delivery_scans.scan_type', DeliveryScan::TYPE_TAG)
            ->groupBy('part_stocks.part_id')
            ->selectRaw('part_stocks.part_id AS part_id, SUM(delivery_scans.qty) AS total')
            ->pluck('total', 'part_id')
            ->map(fn ($value): float => (float) $value)
            ->all();
    }

    /**
     * Tag FG yang benar-benar dipindai, dikelompokkan per part — dipakai
     * dokumen cetak (surat jalan, invoice, packing list).
     *
     * @return array<int, list<string>>
     */
    public function scannedTagsByPart(): array
    {
        return DeliveryScan::query()
            ->join('part_stocks', 'part_stocks.id', '=', 'delivery_scans.part_stock_id')
            ->where('delivery_scans.delivery_id', $this->id)
            ->where('delivery_scans.scan_type', DeliveryScan::TYPE_TAG)
            ->orderByRaw('part_stocks.received_at ASC NULLS LAST')
            ->orderBy('part_stocks.id')
            ->get(['part_stocks.part_id', 'part_stocks.tag'])
            ->groupBy('part_id')
            ->map(fn ($group): array => $group->pluck('tag')->filter()->values()->all())
            ->all();
    }

    /** Baris rencana yang qty-nya belum tertutup tag terpindai. */
    public function uncoveredParts(): array
    {
        $scanned = $this->scannedQtyByPart();

        return collect($this->plannedQtyByPart())
            ->filter(fn (float $qty, $partId): bool => ($scanned[$partId] ?? 0.0) + 1e-9 < $qty)
            ->keys()
            ->map(fn ($partId): int => (int) $partId)
            ->all();
    }

    /** Apakah seluruh rencana qty sudah tertutup tag yang dipindai? */
    public function allTagsScanned(): bool
    {
        $planned = $this->plannedQtyByPart();

        return $planned !== [] && $this->uncoveredParts() === [];
    }

    /** Loading sah bila ada troli dan seluruh rencana qty sudah dipindai. */
    public function loadingComplete(): bool
    {
        return $this->scannedTrolleyCount() > 0 && $this->allTagsScanned();
    }

    /** Stok sudah dipotong saat pemindahan ke area pengiriman? */
    public function stockDeducted(): bool
    {
        return $this->stock_deducted_at !== null;
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

    /**
     * Dokumen (surat jalan, invoice, packing list) dicetak setelah OQC lolos.
     * Nomor PEN/AJU belum ada di tahap ini karena baru terbit dari CEISA.
     */
    public function documentsPrintable(): bool
    {
        return $this->isQcPassed() || $this->isReleased();
    }

    /** Data sudah dirilis ke CEISA? Nomor PEN/AJU baru bisa diisi setelahnya. */
    public function ceisaReleased(): bool
    {
        return $this->ceisa_released_at !== null;
    }

    /** Tahap berjalan untuk tampilan (5 langkah). */
    public function currentStage(): string
    {
        return match (true) {
            $this->isReleased() => 'submitted',
            $this->ceisaReleased() => 'ceisa',
            $this->isQcPassed() => 'oqc',
            $this->isPicked() => 'picked',
            default => 'picking',
        };
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
