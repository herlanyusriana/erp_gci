<?php

namespace App\Services;

use App\Models\Delivery;
use App\Models\DeliveryScan;
use App\Models\Location;
use App\Models\PartStock;
use App\Models\ProductionResult;
use App\Models\Trolley;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Pengiriman barang jadi: validasi tag hasil pindai dan pemindahan barang ke
 * area pengiriman.
 *
 * Stok FG dipotong sekali saat barang dipindah ke area pengiriman (berdasarkan
 * tag yang dipindai), bukan saat submit outgoing. Submit hanya menutup dokumen.
 */
class DeliveryService
{
    /**
     * Pindahkan barang ke area pengiriman dan potong stok sekali.
     *
     * Dipanggil otomatis setelah pemindaian loading lengkap (seluruh rencana
     * qty tertutup tag + minimal satu troli). Potongan dihitung dari tag yang
     * benar-benar dipindai, bukan dari baris rencana.
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
                'status' => __('Outgoing sudah disubmit; barang tidak dapat dipindah lagi.'),
            ]);
        }

        $delivery->loadMissing('items');

        if ($delivery->items->isEmpty()) {
            throw ValidationException::withMessages([
                'items' => __('Tambahkan minimal satu barang sebelum memindahkan.'),
            ]);
        }

        // Pemindahan hanya sah setelah bukti pindai lengkap: seluruh rencana
        // qty tertutup tag FG dan minimal satu troli.
        if ($delivery->scannedTrolleyCount() === 0) {
            throw ValidationException::withMessages([
                'scans' => __('Pindai minimal satu label troli sebelum memindahkan barang.'),
            ]);
        }

        if (! $delivery->allTagsScanned()) {
            throw ValidationException::withMessages([
                'scans' => __('Masih ada :count baris FG yang belum tertutup tag terpindai.', [
                    'count' => count($delivery->uncoveredParts()),
                ]),
            ]);
        }

        DB::transaction(function () use ($delivery, $prepCode): void {
            // Kunci dokumen supaya tombol "Pindahkan" di web dan scan terakhir
            // dari APK tidak memotong stok bersamaan.
            $delivery = Delivery::query()->lockForUpdate()->findOrFail($delivery->id);

            if ($delivery->isReleased()) {
                throw ValidationException::withMessages([
                    'status' => __('Outgoing sudah disubmit; barang tidak dapat dipindah lagi.'),
                ]);
            }

            // Potongan dijaga per scan (`deducted_qty`), bukan per dokumen:
            // setelah OQC menolak, tag pengganti tetap harus dipotong, dan
            // pemindahan ulang tidak boleh memotong tag yang sama dua kali.
            $delivery->loadMissing('scans');

            foreach ($delivery->scans as $scan) {
                if ($scan->scan_type !== DeliveryScan::TYPE_TAG || $scan->part_stock_id === null) {
                    continue;
                }

                if ($scan->deducted_qty !== null) {
                    continue;
                }

                $stock = PartStock::query()->lockForUpdate()->find($scan->part_stock_id);

                if ($stock === null) {
                    throw ValidationException::withMessages([
                        'scans' => __('Stok tag :tag tidak ditemukan; tag mungkin sudah dihapus.', [
                            'tag' => $scan->code,
                        ]),
                    ]);
                }

                if ($scan->origin_location_code === null) {
                    $scan->update(['origin_location_code' => $stock->location_code]);
                }

                $need = round((float) $scan->qty, 4);

                if ($need <= 0) {
                    continue;
                }

                $available = round((float) $stock->qty, 4);

                if ($available + 1e-9 < $need) {
                    throw ValidationException::withMessages([
                        'scans' => __('Stok tag :tag tidak mencukupi: butuh :need, tersedia :available.', [
                            'tag' => $stock->tag ?? $scan->code,
                            'need' => $need,
                            'available' => $available,
                        ]),
                    ]);
                }

                // Tag fisik (beserta labelnya) ikut pindah ke area kirim;
                // qty berkurang sebesar yang benar-benar diambil.
                $stock->update([
                    'qty' => round($available - $need, 4),
                    'location_code' => $prepCode,
                ]);

                $scan->update(['deducted_qty' => $need]);
            }

            $delivery->update([
                'status' => Delivery::STATUS_PICKED,
                'picked_at' => now(),
                'stock_deducted_at' => $delivery->stock_deducted_at ?? now(),
            ]);
        });
    }

    /**
     * Kembalikan barang ke lokasi asal dan pulihkan stok yang sudah dipotong.
     */
    public function returnToOrigin(Delivery $delivery): void
    {
        if ($delivery->isReleased()) {
            throw ValidationException::withMessages([
                'status' => __('Dokumen pengiriman sudah dirilis; barang tidak dapat dikembalikan.'),
            ]);
        }

        DB::transaction(function () use ($delivery): void {
            $delivery = Delivery::query()->lockForUpdate()->findOrFail($delivery->id);

            if ($delivery->isReleased()) {
                throw ValidationException::withMessages([
                    'status' => __('Dokumen pengiriman sudah dirilis; barang tidak dapat dikembalikan.'),
                ]);
            }

            $delivery->loadMissing('scans');

            foreach ($delivery->scans as $scan) {
                if ($scan->scan_type !== DeliveryScan::TYPE_TAG || $scan->part_stock_id === null) {
                    continue;
                }

                // Hanya scan yang benar-benar memotong stok yang dikembalikan.
                // Memakai `qty` sebagai cadangan akan menambah stok untuk scan
                // yang belum pernah dipotong.
                if ($scan->deducted_qty === null) {
                    continue;
                }

                $restore = round((float) $scan->deducted_qty, 4);

                $stock = PartStock::query()->withTrashed()->lockForUpdate()->find($scan->part_stock_id);

                if ($stock === null) {
                    continue;
                }

                if ($restore > 0) {
                    if ($stock->trashed()) {
                        $stock->restore();
                    }

                    $stock->update([
                        'qty' => round((float) $stock->qty + $restore, 4),
                        'location_code' => $scan->origin_location_code ?? $stock->location_code,
                    ]);
                }

                $scan->update(['deducted_qty' => null]);
            }

            $delivery->update([
                'status' => Delivery::STATUS_DRAFT,
                'picked_at' => null,
                'stock_deducted_at' => null,
                'qc_status' => Delivery::QC_PENDING,
                'qc_notes' => null,
                'qc_checked_at' => null,
                'qc_checked_by' => null,
            ]);
        });
    }

    /**
     * OQC per troli: tandai satu troli OK atau NG.
     */
    public function setTrolleyQc(
        Delivery $delivery,
        string $trolleyCode,
        string $status,
        ?int $inspectorId = null,
        ?string $notes = null,
    ): void {
        if ($delivery->isReleased()) {
            throw ValidationException::withMessages([
                'status' => __('Outgoing sudah disubmit; OQC ditutup.'),
            ]);
        }

        $code = $this->normalizeScanCode($trolleyCode);

        $scan = $delivery->scans()
            ->where('scan_type', DeliveryScan::TYPE_TROLLEY)
            ->whereRaw('UPPER(code) = ?', [$code])
            ->first();

        if ($scan === null) {
            throw ValidationException::withMessages([
                'code' => __('Troli :code belum dipindai pada pengiriman ini.', ['code' => $code]),
            ]);
        }

        $scan->update([
            'qc_status' => $status,
            'qc_notes' => $notes,
            'qc_checked_at' => now(),
            'qc_checked_by' => $inspectorId,
        ]);
    }

    /**
     * Tahap 3 — OQC menyatakan seluruh troli lolos.
     */
    public function passQc(Delivery $delivery, ?int $inspectorId = null, ?string $notes = null): void
    {
        if (! $delivery->isPicked()) {
            throw ValidationException::withMessages([
                'status' => __('OQC hanya bisa dilakukan setelah barang berada di area pengiriman.'),
            ]);
        }

        $trolleys = $delivery->scans()->where('scan_type', DeliveryScan::TYPE_TROLLEY)->get();

        if ($trolleys->isEmpty()) {
            throw ValidationException::withMessages([
                'status' => __('Belum ada troli yang dipindai.'),
            ]);
        }

        $unchecked = $trolleys->whereNull('qc_status')->count();
        if ($unchecked > 0) {
            throw ValidationException::withMessages([
                'status' => __('Masih ada :count troli yang belum diperiksa OQC.', ['count' => $unchecked]),
            ]);
        }

        $rejected = $trolleys->where('qc_status', Delivery::QC_REJECTED)->count();
        if ($rejected > 0) {
            throw ValidationException::withMessages([
                'status' => __('Masih ada :count troli berstatus NG.', ['count' => $rejected]),
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

        $delivery->scans()
            ->where('scan_type', DeliveryScan::TYPE_TROLLEY)
            ->update(['qc_status' => null, 'qc_notes' => null, 'qc_checked_at' => null, 'qc_checked_by' => null]);

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
     * Rilis data pengiriman ke CEISA.
     *
     * Nomor PEN/AJU terbit dari CEISA, jadi tahap ini harus dilalui sebelum
     * nomor tersebut boleh diisi dan outgoing disubmit.
     */
    public function releaseCeisa(Delivery $delivery, ?int $userId = null): void
    {
        if ($delivery->isReleased()) {
            throw ValidationException::withMessages([
                'status' => __('Outgoing sudah disubmit; data CEISA tidak dapat dirilis ulang.'),
            ]);
        }

        if (! $delivery->isQcPassed()) {
            throw ValidationException::withMessages([
                'status' => __('Data CEISA dirilis setelah OQC lolos.'),
            ]);
        }

        if ($delivery->ceisaReleased()) {
            throw ValidationException::withMessages([
                'status' => __('Data CEISA sudah dirilis untuk pengiriman ini.'),
            ]);
        }

        $delivery->update([
            'ceisa_released_at' => now(),
            'ceisa_released_by' => $userId,
        ]);
    }

    /**
     * Tahap akhir — catat sebagai outgoing.
     *
     * Syarat: OQC lolos dan No. PEN/AJU terisi. Stok sudah dipotong saat
     * barang dipindah ke area pengiriman; submit hanya menutup dokumen.
     */
    public function submitOutgoing(Delivery $delivery): void
    {
        if ($delivery->isReleased()) {
            throw ValidationException::withMessages([
                'status' => __('Outgoing ini sudah disubmit.'),
            ]);
        }

        if (! $delivery->isQcPassed()) {
            throw ValidationException::withMessages([
                'status' => __('Outgoing hanya bisa disubmit setelah OQC lolos.'),
            ]);
        }

        if (! $delivery->ceisaReleased()) {
            throw ValidationException::withMessages([
                'status' => __('Rilis data CEISA dulu sebelum mengisi PEN/AJU dan submit.'),
            ]);
        }

        if (trim((string) $delivery->pen_no) === '' || trim((string) $delivery->aju_no) === '') {
            throw ValidationException::withMessages([
                'pen_no' => __('No. PEN dan No. AJU wajib diisi sebelum submit.'),
            ]);
        }

        $delivery->loadMissing('items');

        if ($delivery->items->isEmpty()) {
            throw ValidationException::withMessages([
                'items' => __('Tambahkan minimal satu barang sebelum submit.'),
            ]);
        }

        DB::transaction(function () use ($delivery): void {
            $delivery->update([
                'status' => Delivery::STATUS_RELEASED,
                'released_at' => now(),
            ]);
        });
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
            return $this->resolveTrolley($delivery, trim((string) ($payload['code'] ?? $raw)));
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
            return $this->resolveTrolley($delivery, $raw);
        }

        // Baris pengiriman berupa rencana (part + qty), bukan tag. Tag sah bila
        // part-nya termasuk dalam rencana pengiriman ini.
        $planned = (float) $delivery->items()->where('part_id', $stock->part_id)->sum('qty');

        if ($planned <= 0) {
            throw ValidationException::withMessages([
                'code' => __('Tag :tag bukan bagian dari pengiriman ini.', ['tag' => $stock->tag ?? '-']),
            ]);
        }

        // Satu tag FG hanya boleh diklaim satu dokumen yang belum dirilis.
        // Dokumen lain yang masih draft/picked/qc_passed menahan tag tersebut.
        $claiming = DeliveryScan::query()
            ->where('scan_type', DeliveryScan::TYPE_TAG)
            ->where('part_stock_id', $stock->id)
            ->where('delivery_id', '!=', $delivery->id)
            ->whereHas('delivery', fn ($query) => $query->where('status', '!=', Delivery::STATUS_RELEASED))
            ->with('delivery:id,delivery_no')
            ->first();

        if ($claiming !== null) {
            throw ValidationException::withMessages([
                'code' => __('Tag :tag sudah dipakai pengiriman :no.', [
                    'tag' => $stock->tag ?? '-',
                    'no' => $claiming->delivery?->delivery_no ?? '-',
                ]),
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
    public function recordScan(Delivery $delivery, string $raw, ?int $userId = null, ?float $qty = null): array
    {
        return DB::transaction(function () use ($delivery, $raw, $userId, $qty): array {
            // Kunci dokumen supaya pemindaian terakhir dari APK dan tombol
            // "Pindahkan" di web tidak memotong stok bersamaan.
            $delivery = Delivery::query()->lockForUpdate()->findOrFail($delivery->id);

            // Pemindaian hanya pada tahap sebelum barang dipindah. Setelah pindah,
            // stok sudah dipotong sehingga scan tambahan akan membuat selisih.
            if (! $delivery->isDraft()) {
                throw ValidationException::withMessages([
                    'code' => __('Pemindaian hanya tersedia sebelum barang dipindah ke area pengiriman.'),
                ]);
            }

            $resolved = $this->resolveScan($delivery, $raw);

            $existing = $delivery->scans()
                ->where('scan_type', $resolved['scan_type'])
                ->where('code', $resolved['code'])
                ->first();

            if ($existing !== null) {
                return [
                    'scan_type' => $resolved['scan_type'],
                    'code' => $resolved['code'],
                    'duplicate' => true,
                    'loading_complete' => $delivery->loadingComplete(),
                    'status' => (string) $delivery->status,
                ];
            }

            $scanQty = $resolved['scan_type'] === DeliveryScan::TYPE_TAG
                ? $this->resolveScanQty($delivery, (int) $resolved['part_stock_id'], $qty)
                : null;

            DeliveryScan::create([
                'delivery_id' => $delivery->id,
                'scan_type' => $resolved['scan_type'],
                'code' => $resolved['code'],
                'part_stock_id' => $resolved['part_stock_id'],
                'qty' => $scanQty,
                'scanned_by' => $userId,
                'scanned_at' => now(),
            ]);

            $fresh = $delivery->fresh();

            // Begitu seluruh rencana qty tertutup dan minimal satu troli terpindai,
            // barang dianggap sudah berada di area pengiriman dan stok dipotong.
            if ($fresh->loadingComplete()) {
                $this->moveToPreparation($fresh);
                $fresh = $delivery->fresh();
            }

            return [
                'scan_type' => $resolved['scan_type'],
                'code' => $resolved['code'],
                'duplicate' => false,
                'loading_complete' => $fresh->loadingComplete(),
                'status' => (string) $fresh->status,
            ];
        });
    }

    /**
     * Qty tag yang boleh diambil: tidak melebihi stok tag dan sisa rencana.
     */
    private function resolveScanQty(Delivery $delivery, int $stockId, ?float $qty): float
    {
        $stock = PartStock::query()->find($stockId);

        if ($stock === null) {
            throw ValidationException::withMessages([
                'code' => __('Tag tidak ditemukan.'),
            ]);
        }

        $available = round((float) $stock->qty, 4);

        if ($available <= 0) {
            throw ValidationException::withMessages([
                'qty' => __('Tag :tag sudah tidak punya stok.', ['tag' => $stock->tag ?? '-']),
            ]);
        }

        $planned = (float) $delivery->items()->where('part_id', $stock->part_id)->sum('qty');
        $remaining = round($planned - ($delivery->scannedQtyByPart()[$stock->part_id] ?? 0.0), 4);

        if ($remaining <= 0) {
            throw ValidationException::withMessages([
                'qty' => __('Rencana qty part ini sudah terpenuhi.'),
            ]);
        }

        // Tanpa qty eksplisit (scan cadangan web), ambil seperlunya saja.
        $requested = $qty !== null ? round($qty, 4) : min($available, $remaining);

        if ($requested <= 0) {
            throw ValidationException::withMessages([
                'qty' => __('Qty hasil pindai harus lebih dari 0.'),
            ]);
        }

        if ($requested > $available + 1e-9) {
            throw ValidationException::withMessages([
                'qty' => __('Qty :qty melebihi stok tag :tag (:available).', [
                    'qty' => $requested,
                    'tag' => $stock->tag ?? '-',
                    'available' => $available,
                ]),
            ]);
        }

        if ($requested > $remaining + 1e-9) {
            throw ValidationException::withMessages([
                'qty' => __('Qty :qty melebihi sisa rencana :remaining.', [
                    'qty' => $requested,
                    'remaining' => $remaining,
                ]),
            ]);
        }

        return $requested;
    }

    /**
     * Satu label fisik per tag yang dipindai; bila belum ada scan, label
     * dicetak dari baris rencana supaya dokumen tidak kosong.
     *
     * @return list<array<string, mixed>>
     */
    public function labelEntries(Delivery $delivery): array
    {
        $scans = $delivery->scans
            ->where('scan_type', DeliveryScan::TYPE_TAG)
            ->filter(fn (DeliveryScan $scan): bool => $scan->part_stock_id !== null);

        if ($scans->isEmpty()) {
            return $delivery->items->map(fn ($item): array => [
                'tag' => (string) ($item->tag ?? ''),
                'part_stock_id' => (int) ($item->part_stock_id ?? 0),
                'part_id' => (int) $item->part_id,
                'part_number' => $item->part?->part_number,
                'part_name' => $item->part?->part_name,
                'qty' => round((float) $item->qty, 4),
                'qty_unit' => $item->qty_unit,
            ])->values()->all();
        }

        $stocks = PartStock::query()
            ->with('part:id,part_number,part_name')
            ->whereIn('id', $scans->pluck('part_stock_id')->unique())
            ->get()
            ->keyBy('id');

        return $scans
            ->map(function (DeliveryScan $scan) use ($stocks): array {
                $stock = $stocks[$scan->part_stock_id] ?? null;

                return [
                    'tag' => (string) ($stock?->tag ?? $scan->code),
                    'part_stock_id' => (int) $scan->part_stock_id,
                    'part_id' => (int) ($stock?->part_id ?? 0),
                    'part_number' => $stock?->part?->part_number,
                    'part_name' => $stock?->part?->part_name,
                    'qty' => round((float) $scan->qty, 4),
                    'qty_unit' => $stock?->qty_unit,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Ringkasan progres loading untuk layar scan.
     *
     * @return array<string, mixed>
     */
    public function loadingContext(Delivery $delivery): array
    {
        $delivery->loadMissing([
            'customer:id,customer_code,customer_name',
            'pickingList:id,picking_no',
            'items.part:id,part_number,part_name',
        ]);

        $scannedByPart = $delivery->scannedQtyByPart();

        // Status per baris rencana: qty part dianggap tertutup bila total tag
        // terpindai untuk part itu sudah mencapai total rencananya.
        $covered = $delivery->items
            ->groupBy('part_id')
            ->map(fn (Collection $rows, $partId): bool => ($scannedByPart[$partId] ?? 0.0) + 1e-9 >= (float) $rows->sum('qty'))
            ->all();

        return [
            'delivery_id' => (int) $delivery->id,
            'delivery_no' => (string) $delivery->delivery_no,
            'picking_no' => $delivery->pickingList?->picking_no,
            'status' => (string) $delivery->status,
            'qc_status' => (string) $delivery->qc_status,
            'customer_name' => $delivery->customer?->customer_name,
            'truck_no' => $delivery->truck_no,
            'driver_name' => $delivery->driver_name,
            'trolleys' => $delivery->scans()
                ->where('scan_type', DeliveryScan::TYPE_TROLLEY)
                ->orderBy('id')
                ->get()
                ->map(fn (DeliveryScan $scan): array => [
                    'code' => (string) $scan->code,
                    'qc_status' => $scan->qc_status,
                    'qc_notes' => $scan->qc_notes,
                ])
                ->all(),
            'trolley_codes' => $delivery->scans()
                ->where('scan_type', DeliveryScan::TYPE_TROLLEY)
                ->orderBy('id')
                ->pluck('code')
                ->all(),
            'pen_no' => $delivery->pen_no,
            'aju_no' => $delivery->aju_no,
            'items' => $delivery->items->sortBy('sequence')->map(fn ($item): array => [
                'id' => (int) $item->id,
                'sequence' => (int) $item->sequence,
                'is_picked' => (bool) $item->is_picked,
                'part_id' => (int) $item->part_id,
                'part_stock_id' => $item->part_stock_id !== null ? (int) $item->part_stock_id : 0,
                'tag' => (string) ($item->tag ?? ''),
                'part_no' => $item->part?->part_number,
                'part_name' => $item->part?->part_name,
                'qty' => round((float) $item->qty, 4),
                'uom' => $item->qty_unit,
                'scanned_qty' => round($scannedByPart[$item->part_id] ?? 0.0, 4),
                'scanned' => (bool) ($covered[$item->part_id] ?? false),
            ])->values()->all(),
            'scanned_tags' => count(array_filter($covered)),
            'total_tags' => count($covered),
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
    private function resolveTrolley(Delivery $delivery, string $code): array
    {
        $normalized = $this->normalizeScanCode($code);

        if ($normalized === '') {
            throw ValidationException::withMessages([
                'code' => __('Kode troli kosong.'),
            ]);
        }

        // Satu troli fisik hanya boleh dipakai satu dokumen yang belum dirilis.
        $claiming = DeliveryScan::query()
            ->where('scan_type', DeliveryScan::TYPE_TROLLEY)
            ->where('code', $normalized)
            ->where('delivery_id', '!=', $delivery->id)
            ->whereHas('delivery', fn ($query) => $query->where('status', '!=', Delivery::STATUS_RELEASED))
            ->with('delivery:id,delivery_no')
            ->first();

        if ($claiming !== null) {
            throw ValidationException::withMessages([
                'code' => __('Troli :code sudah dipakai pengiriman :no.', [
                    'code' => $normalized,
                    'no' => $claiming->delivery?->delivery_no ?? '-',
                ]),
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

        if (! $trolley->is_active) {
            throw ValidationException::withMessages([
                'code' => __('Troli :code sedang tidak aktif; hubungi admin master troli.', ['code' => $normalized]),
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
        // Dihitung dari SEMUA baris, bukan hanya yang aktif: mode longgar hanya
        // untuk master yang belum pernah diisi. Kalau semua troli dinonaktifkan,
        // validasi tetap berlaku sehingga pindai ditolak, bukan diterima bebas.
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
            ->active()
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
     * Invoice hanya pelengkap dokumen; nomornya boleh diisi sebelum cetak.
     */
    public function setInvoiceNo(Delivery $delivery, ?string $invoiceNo): void
    {
        if ($delivery->isReleased()) {
            throw ValidationException::withMessages([
                'invoice_no' => __('Outgoing sudah disubmit; invoice tidak dapat diubah.'),
            ]);
        }

        $delivery->update(['invoice_no' => $invoiceNo !== null ? trim($invoiceNo) : null]);
    }

    /**
     * Simpan dokumen kepabeanan. No. PEN/AJU hanya boleh diisi setelah data
     * dirilis ke CEISA.
     *
     * @param  array{pen_no?: string|null, aju_no?: string|null, invoice_no?: string|null}  $data
     */
    public function saveDocuments(Delivery $delivery, array $data, ?int $userId = null): void
    {
        if ($delivery->isReleased()) {
            throw ValidationException::withMessages([
                'status' => __('Outgoing sudah disubmit; dokumen tidak dapat diubah.'),
            ]);
        }

        $penNo = trim((string) ($data['pen_no'] ?? ''));
        $ajuNo = trim((string) ($data['aju_no'] ?? ''));

        if (($penNo !== '' || $ajuNo !== '') && ! $delivery->ceisaReleased()) {
            throw ValidationException::withMessages([
                'pen_no' => __('Rilis data CEISA dulu sebelum mengisi No. PEN/AJU.'),
            ]);
        }

        $delivery->update([
            'pen_no' => $penNo !== '' ? $penNo : null,
            'aju_no' => $ajuNo !== '' ? $ajuNo : null,
            'invoice_no' => array_key_exists('invoice_no', $data)
                ? (trim((string) $data['invoice_no']) !== '' ? trim((string) $data['invoice_no']) : null)
                : $delivery->invoice_no,
            'updated_by' => $userId,
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
}
