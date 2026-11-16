<?php

namespace App\Services;

use App\Models\Delivery;
use App\Models\DeliveryScan;
use App\Models\Location;
use App\Models\Part;
use App\Models\PartStock;
use App\Models\ProductionResult;
use App\Models\Trolley;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Pengiriman barang jadi: saran picking FIFO, validasi tag, dan pemindahan
 * lokasi ke area pengiriman.
 *
 * Stok total tidak berubah saat pemindahan; yang berubah hanya lokasi fisik
 * barang. Barang baru benar-benar keluar dari pabrik saat dokumen dikirim.
 */
class DeliveryService
{
    /**
     * Tag FG yang tersedia untuk dikirim, urut FIFO (tertua dulu).
     *
     * @return list<array<string, mixed>>
     */
    public function availableTags(?int $partId = null, ?string $search = null, int $limit = 50): array
    {
        $prepCode = $this->deliveryPrepLocationCode();

        return PartStock::query()
            ->with(['part:id,part_number,part_name,part_type_id', 'part.partType:id,code'])
            ->where('qty', '>', 0)
            ->whereNotNull('tag')
            ->when($partId !== null, fn ($q) => $q->where('part_id', $partId))
            ->when($search !== null && $search !== '', fn ($q) => $q
                ->where(fn ($w) => $w
                    ->where('tag', 'ilike', "%{$search}%")
                    ->orWhereHas('part', fn ($p) => $p
                        ->where('part_number', 'ilike', "%{$search}%")
                        ->orWhere('part_name', 'ilike', "%{$search}%"))))
            ->orderByRaw('received_at ASC NULLS LAST')
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->filter(fn (PartStock $stock): bool => $this->isFinishedGood($stock))
            ->map(fn (PartStock $stock): array => [
                'part_stock_id' => (int) $stock->id,
                'part_id' => (int) $stock->part_id,
                'part_number' => $stock->part?->part_number,
                'part_name' => $stock->part?->part_name,
                'tag' => (string) $stock->tag,
                'qty' => round((float) $stock->qty, 4),
                'qty_unit' => $stock->qty_unit,
                'location_code' => $stock->location_code,
                'received_at' => $stock->received_at?->toIso8601String(),
                'in_prep' => $prepCode !== null && $stock->location_code === $prepCode,
            ])
            ->values()
            ->all();
    }

    /**
     * Tag yang sah untuk satu baris pengiriman: harus barang jadi dan berstok.
     */
    public function assertDeliverable(PartStock $stock, float $qty): void
    {
        if (! $this->isFinishedGood($stock)) {
            throw ValidationException::withMessages([
                'items' => __('Tag :tag bukan barang jadi.', ['tag' => $stock->tag ?? '-']),
            ]);
        }

        if ((float) $stock->qty + 1e-9 < $qty) {
            throw ValidationException::withMessages([
                'items' => __('Qty :qty melebihi stok tag :tag (:available).', [
                    'qty' => $qty,
                    'tag' => $stock->tag ?? '-',
                    'available' => (float) $stock->qty,
                ]),
            ]);
        }
    }

    /**
     * Pindahkan seluruh tag pada dokumen ke area pengiriman dan tandai picked.
     */
    public function moveToPreparation(Delivery $delivery): void
    {
        $prepCode = $this->deliveryPrepLocationCode();

        if ($prepCode === null) {
            throw ValidationException::withMessages([
                'status' => __('Lokasi Delivery Preparation Area belum diatur di master lokasi.'),
            ]);
        }

        if ($delivery->isReleased()) {
            throw ValidationException::withMessages([
                'status' => __('Dokumen pengiriman sudah dirilis; barang tidak dapat dipindah lagi.'),
            ]);
        }

        $delivery->loadMissing('items');

        if ($delivery->items->isEmpty()) {
            throw ValidationException::withMessages([
                'items' => __('Tambahkan minimal satu barang sebelum memindahkan.'),
            ]);
        }

        foreach ($delivery->items as $item) {
            $stock = PartStock::query()->lockForUpdate()->find($item->part_stock_id);

            if ($stock === null) {
                throw ValidationException::withMessages([
                    'items' => __('Stok tag :tag tidak ditemukan.', ['tag' => $item->tag ?? '-']),
                ]);
            }

            // Simpan lokasi asal SEBELUM dipindah agar pengembalian presisi.
            if ($item->origin_location_code === null) {
                $item->update(['origin_location_code' => $stock->location_code]);
            }

            $stock->update(['location_code' => $prepCode]);
        }

        $delivery->update([
            'status' => Delivery::STATUS_PICKED,
            'picked_at' => now(),
        ]);
    }

    /**
     * Kembalikan tag ke lokasi asal dan turunkan status ke draft.
     */
    public function returnToOrigin(Delivery $delivery): void
    {
        if ($delivery->isReleased()) {
            throw ValidationException::withMessages([
                'status' => __('Dokumen pengiriman sudah dirilis; barang tidak dapat dikembalikan.'),
            ]);
        }

        $delivery->loadMissing('items');

        foreach ($delivery->items as $item) {
            if ($item->origin_location_code === null) {
                continue;
            }

            PartStock::query()
                ->whereKey($item->part_stock_id)
                ->update(['location_code' => $item->origin_location_code]);
        }

        $delivery->update([
            'status' => Delivery::STATUS_DRAFT,
            'picked_at' => null,
            'qc_status' => Delivery::QC_PENDING,
            'qc_notes' => null,
            'qc_checked_at' => null,
            'qc_checked_by' => null,
            'loaded_at' => null,
        ]);
    }

    /**
     * Tahap 3 — QC memeriksa barang di area pengiriman.
     */
    public function passQc(Delivery $delivery, ?int $inspectorId = null, ?string $notes = null): void
    {
        if (! $delivery->isPicked()) {
            throw ValidationException::withMessages([
                'status' => __('QC hanya bisa dilakukan setelah barang berada di area pengiriman.'),
            ]);
        }

        $delivery->update([
            'status' => Delivery::STATUS_QC_PASSED,
            'qc_status' => Delivery::QC_PASSED,
            'qc_notes' => $notes,
            'qc_checked_at' => now(),
            'qc_checked_by' => $inspectorId,
        ]);
    }

    /**
     * QC menolak: status kembali ke draft agar picking diperbaiki.
     */
    public function rejectQc(Delivery $delivery, ?int $inspectorId = null, ?string $notes = null): void
    {
        if (! $delivery->isPicked()) {
            throw ValidationException::withMessages([
                'status' => __('QC hanya bisa dilakukan setelah barang berada di area pengiriman.'),
            ]);
        }

        $delivery->update([
            'status' => Delivery::STATUS_DRAFT,
            'qc_status' => Delivery::QC_REJECTED,
            'qc_notes' => $notes,
            'qc_checked_at' => now(),
            'qc_checked_by' => $inspectorId,
            'picked_at' => null,
        ]);
    }

    /**
     * Tahap 4 — rilis label pengiriman dan tandai barang dimuat ke truk.
     */
    public function releaseLabel(Delivery $delivery): void
    {
        if (! $delivery->isQcPassed()) {
            throw ValidationException::withMessages([
                'status' => __('Label hanya bisa dirilis setelah QC menyatakan lolos.'),
            ]);
        }

        // Loading harus dibuktikan lewat scan troli dan scan label FG.
        if ($delivery->scannedTrolleyCount() === 0) {
            throw ValidationException::withMessages([
                'scans' => __('Pindai minimal satu label troli sebelum merilis label.'),
            ]);
        }

        if (! $delivery->allTagsScanned()) {
            $missing = $delivery->items()->count() - $delivery->scannedTagCount();

            throw ValidationException::withMessages([
                'scans' => __('Masih ada :count tag FG yang belum dipindai.', ['count' => $missing]),
            ]);
        }

        $delivery->update([
            'status' => Delivery::STATUS_LOADED,
            'loaded_at' => now(),
        ]);
    }

    /**
     * Resolusi hasil pindai: QR troli, QR label FG produksi, atau tag stok FG.
     *
     * @return array{scan_type: string, code: string, part_stock_id: int|null}
     *
     * @throws ValidationException bila kode tidak sah untuk pengiriman ini
     */
    public function resolveScan(Delivery $delivery, string $raw): array
    {
        $raw = trim($raw);

        if ($raw === '') {
            throw ValidationException::withMessages([
                'code' => __('Kode hasil pindai kosong.'),
            ]);
        }

        $payload = $this->decodeScanPayload($raw);
        $payloadType = $payload['type'] ?? null;

        // QR troli dari aplikasi Trolley Scan berbentuk:
        // {"code":"B001","type":"external","status":"in","generated_at":"..."}
        // `type` di sana berarti jenis troli (internal/external), bukan penanda
        // dokumen. Jadi selain QR produksi (fg/wip), payload berisi `code`
        // diperlakukan sebagai troli.
        if ($payloadType === 'trolley' || (isset($payload['code']) && ! in_array($payloadType, ['fg', 'wip'], true))) {
            return $this->resolveTrolley(trim((string) ($payload['code'] ?? $raw)));
        }

        // QR label FG produksi: {type: 'fg', result_id, part_id}
        $stockId = null;

        if (in_array($payload['type'] ?? null, ['fg', 'wip'], true) && isset($payload['result_id'])) {
            $stockId = ProductionResult::query()
                ->whereKey((int) $payload['result_id'])
                ->value('output_part_stock_id');
        }

        $stock = $stockId !== null
            ? PartStock::query()->with('part:id,part_number,part_name,part_type_id', 'part.partType:id,code')->find((int) $stockId)
            : $this->findStockByTag($raw);

        if ($stock === null) {
            // Bukan label FG yang dikenal: perlakukan sebagai kode troli.
            return $this->resolveTrolley($raw);
        }

        $belongs = $delivery->items()->where('part_stock_id', $stock->id)->exists();

        if (! $belongs) {
            throw ValidationException::withMessages([
                'code' => __('Tag :tag bukan bagian dari pengiriman ini.', ['tag' => $stock->tag ?? '-']),
            ]);
        }

        return [
            'scan_type' => DeliveryScan::TYPE_TAG,
            'code' => $this->normalizeScanCode((string) $stock->tag),
            'part_stock_id' => (int) $stock->id,
        ];
    }

    /**
     * Catat hasil pindai. Idempoten: kode yang sama tidak digandakan.
     *
     * @return array{scan_type: string, code: string, duplicate: bool, loading_complete: bool}
     */
    public function recordScan(Delivery $delivery, string $raw, ?int $userId = null): array
    {
        if ($delivery->isReleased()) {
            throw ValidationException::withMessages([
                'code' => __('Dokumen sudah dirilis; pemindaian ditutup.'),
            ]);
        }

        if (! $delivery->isQcPassed() && ! $delivery->isLoaded()) {
            throw ValidationException::withMessages([
                'code' => __('Pemindaian loading hanya tersedia setelah QC lolos.'),
            ]);
        }

        $resolved = $this->resolveScan($delivery, $raw);

        $scan = DeliveryScan::query()->firstOrCreate(
            [
                'delivery_id' => $delivery->id,
                'scan_type' => $resolved['scan_type'],
                'code' => $resolved['code'],
            ],
            [
                'part_stock_id' => $resolved['part_stock_id'],
                'scanned_by' => $userId,
                'scanned_at' => now(),
            ],
        );

        return [
            'scan_type' => $resolved['scan_type'],
            'code' => $resolved['code'],
            'duplicate' => ! $scan->wasRecentlyCreated,
            'loading_complete' => $delivery->fresh()->loadingComplete(),
        ];
    }

    /**
     * Ringkasan progres loading untuk layar scan.
     *
     * @return array<string, mixed>
     */
    public function loadingContext(Delivery $delivery): array
    {
        $delivery->loadMissing(['customer:id,customer_code,customer_name', 'items.part:id,part_number,part_name']);

        $scannedStockIds = $delivery->scans()
            ->where('scan_type', DeliveryScan::TYPE_TAG)
            ->pluck('part_stock_id')
            ->filter()
            ->all();

        return [
            'delivery_id' => (int) $delivery->id,
            'delivery_no' => (string) $delivery->delivery_no,
            'status' => (string) $delivery->status,
            'qc_status' => (string) $delivery->qc_status,
            'customer_name' => $delivery->customer?->customer_name,
            'truck_no' => $delivery->truck_no,
            'driver_name' => $delivery->driver_name,
            'trolleys' => $delivery->scans()
                ->where('scan_type', DeliveryScan::TYPE_TROLLEY)
                ->orderBy('id')
                ->pluck('code')
                ->all(),
            'items' => $delivery->items->map(fn ($item): array => [
                'id' => (int) $item->id,
                'part_stock_id' => (int) $item->part_stock_id,
                'tag' => (string) ($item->tag ?? ''),
                'part_no' => $item->part?->part_number,
                'part_name' => $item->part?->part_name,
                'qty' => round((float) $item->qty, 4),
                'uom' => $item->qty_unit,
                'scanned' => in_array((int) $item->part_stock_id, $scannedStockIds, true),
            ])->values()->all(),
            'scanned_tags' => count($scannedStockIds),
            'total_tags' => $delivery->items->count(),
            'loading_complete' => $delivery->loadingComplete(),
        ];
    }

    /**
     * Validasi kode troli terhadap master hasil sinkronisasi aplikasi Trolley
     * Scan. Bila master belum pernah disinkronkan, kode diterima apa adanya
     * supaya loading tidak terkunci sebelum integrasi disiapkan.
     *
     * @return array{scan_type: string, code: string, part_stock_id: null}
     */
    private function resolveTrolley(string $code): array
    {
        $normalized = $this->normalizeScanCode($code);

        if ($normalized === '') {
            throw ValidationException::withMessages([
                'code' => __('Kode troli kosong.'),
            ]);
        }

        if (! config('trolley.require_master') || $this->trolleyMasterIsEmpty()) {
            return [
                'scan_type' => DeliveryScan::TYPE_TROLLEY,
                'code' => $normalized,
                'part_stock_id' => null,
            ];
        }

        $trolley = Trolley::query()
            ->whereRaw('UPPER(code) = ?', [$normalized])
            ->first();

        if ($trolley === null) {
            throw ValidationException::withMessages([
                'code' => __('Troli :code tidak terdaftar di master troli.', ['code' => $normalized]),
            ]);
        }

        return [
            'scan_type' => DeliveryScan::TYPE_TROLLEY,
            'code' => $normalized,
            'part_stock_id' => null,
        ];
    }

    /** Apakah master troli belum pernah disinkronkan? */
    public function trolleyMasterIsEmpty(): bool
    {
        return ! Trolley::query()->exists();
    }

    /**
     * Troli yang bisa dipakai, diutamakan yang jenisnya cocok dengan model FG
     * pada pengiriman (kind: reinforce / backplate / compbase).
     *
     * @return list<array<string, mixed>>
     */
    public function availableTrolleys(?string $preferredKind = null, int $limit = 500): array
    {
        return Trolley::query()
            ->when($preferredKind !== null && $preferredKind !== '', fn ($q) => $q->orderByRaw(
                'CASE WHEN LOWER(kind) = ? THEN 0 ELSE 1 END',
                [strtolower($preferredKind)],
            ))
            ->orderBy('code')
            ->limit($limit)
            ->get()
            ->map(fn (Trolley $trolley): array => [
                'code' => (string) $trolley->code,
                'kind' => $trolley->kind,
                'kind_label' => $trolley->kindLabel(),
                'location' => $trolley->location,
                'capacity' => $trolley->capacity,
            ])
            ->values()
            ->all();
    }

    /** Cari stok FG berdasarkan tag (case-insensitive). */
    private function findStockByTag(string $tag): ?PartStock
    {
        return PartStock::query()
            ->with('part:id,part_number,part_name,part_type_id', 'part.partType:id,code')
            ->whereRaw('LOWER(COALESCE(tag, \'\')) = ?', [mb_strtolower(trim($tag))])
            ->first();
    }

    /** @return array<string, mixed> */
    private function decodeScanPayload(string $raw): array
    {
        if (! str_starts_with($raw, '{')) {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

            return is_array($decoded) ? $decoded : [];
        } catch (\JsonException) {
            return [];
        }
    }

    private function normalizeScanCode(string $code): string
    {
        return strtoupper(trim($code));
    }

    /**
     * Tahap 5 — rilis surat jalan, invoice, dan packing list.
     */
    public function releaseDocuments(Delivery $delivery): void
    {
        if (! $delivery->isLoaded()) {
            throw ValidationException::withMessages([
                'status' => __('Dokumen hanya bisa dirilis setelah label dirilis dan barang dimuat.'),
            ]);
        }

        if (trim((string) $delivery->invoice_no) === '') {
            throw ValidationException::withMessages([
                'invoice_no' => __('Isi nomor invoice sebelum merilis dokumen.'),
            ]);
        }

        $delivery->update([
            'status' => Delivery::STATUS_RELEASED,
            'released_at' => now(),
        ]);
    }

    /** Kode lokasi bertipe area pengiriman. */
    public function deliveryPrepLocationCode(): ?string
    {
        return Location::query()
            ->where('type', Location::TYPE_DELIVERY_PREP)
            ->where('is_active', true)
            ->orderBy('code')
            ->value('code');
    }

    /**
     * Saran FIFO per part: tag tertua untuk tiap part yang diminta.
     *
     * @param  list<int>  $partIds
     * @return array<int, array<string, mixed>>
     */
    public function suggestFifo(array $partIds): array
    {
        if ($partIds === []) {
            return [];
        }

        return PartStock::query()
            ->with('part:id,part_number,part_name')
            ->whereIn('part_id', $partIds)
            ->where('qty', '>', 0)
            ->whereNotNull('tag')
            ->orderByRaw('received_at ASC NULLS LAST')
            ->orderBy('id')
            ->get()
            ->groupBy('part_id')
            ->map(fn (Collection $group, $partId): array => [
                'part_id' => (int) $partId,
                'part_number' => $group->first()?->part?->part_number,
                'tag' => (string) $group->first()?->tag,
                'part_stock_id' => (int) $group->first()?->id,
                'qty' => round((float) $group->first()?->qty, 4),
                'qty_unit' => $group->first()?->qty_unit,
                'location_code' => $group->first()?->location_code,
            ])
            ->values()
            ->all();
    }

    /** Apakah stok ini barang jadi? */
    public function isFinishedGood(PartStock $stock): bool
    {
        $stock->loadMissing('part.partType');

        return strtoupper((string) $stock->part?->partType?->code) === 'FG';
    }

    /**
     * Part bertipe FG yang masih punya stok, untuk dropdown pilih part.
     *
     * @return list<array<string, mixed>>
     */
    public function deliverableParts(): array
    {
        return Part::query()
            ->whereHas('partType', fn ($q) => $q->whereRaw('UPPER(code) = ?', ['FG']))
            ->whereIn('id', PartStock::query()->where('qty', '>', 0)->select('part_id'))
            ->orderBy('part_number')
            ->get(['id', 'part_number', 'part_name'])
            ->map(fn (Part $part): array => [
                'id' => (int) $part->id,
                'part_number' => $part->part_number,
                'part_name' => $part->part_name,
            ])
            ->all();
    }
}
