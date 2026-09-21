<?php

namespace App\Services;

use App\Models\IncomingArrival;
use App\Models\IncomingArrivalItem;
use App\Models\IncomingReceive;
use App\Models\PartStock;
use App\Models\WorkOrderMaterialBooking;
use App\Support\UomCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReceiveMaterialService
{
    /**
     * Normalize a receive tag: uppercase + trim, empty → null.
     */
    public function normalizeTag(?string $tag): ?string
    {
        $tag = trim((string) $tag);
        if ($tag === '') {
            return null;
        }

        return strtoupper($tag);
    }

    /**
     * Resolve system tag when a physical tag is missing (local no-tag receive).
     */
    public function resolveReceiveTag(?string $tag, ?int $receiveId = null, $receivedAt = null): ?string
    {
        $tag = $this->normalizeTag($tag);
        if ($tag !== null) {
            return $tag;
        }
        if ($receiveId === null) {
            return null;
        }

        $receivedAt ??= now();

        return 'AUTO-'.$receivedAt->format('ymd').'-'.str_pad((string) $receiveId, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Ensure tags are unique within a single arrival item.
     */
    public function ensureTagsUniqueForArrivalItem(
        IncomingArrivalItem $arrivalItem,
        array $tags,
        string $errorKey = 'tags',
        ?int $ignoreReceiveId = null
    ): void {
        $seen = [];
        foreach ($tags as $idx => $tagData) {
            $tag = $this->normalizeTag($tagData['tag'] ?? null);
            if ($tag === null) {
                continue;
            }
            $key = $errorKey.'.'.$idx.'.tag';
            if (isset($seen[$tag])) {
                throw ValidationException::withMessages([
                    $key => __("TAG ':tag' duplikat dalam item ini.", ['tag' => $tag]),
                ]);
            }
            $seen[$tag] = true;

            $existing = IncomingReceive::query()
                ->where('arrival_item_id', $arrivalItem->id)
                ->where('tag', $tag)
                ->when($ignoreReceiveId !== null, fn ($q) => $q->where('id', '!=', $ignoreReceiveId))
                ->exists();
            if ($existing) {
                throw ValidationException::withMessages([
                    $key => __("TAG ':tag' sudah dipakai pada item ini.", ['tag' => $tag]),
                ]);
            }
        }
    }

    /**
     * Berat (KGM) yang diposting ke stok dari sebuah receive.
     * Basis keputusan: net_weight ?? weight ?? qty (fallback; semua dalam KGM).
     */
    public function postedWeight(IncomingReceive $receive): float
    {
        return (float) ($receive->net_weight ?? $receive->weight ?? $receive->qty ?? 0);
    }

    /**
     * Apakah item ini pakai basis berat (KGM)? Ya bila weight_nett (rencana) > 0.
     * Local PO (tanpa berat) tetap pakai basis qty_goods.
     */
    public function usesWeightBasis(IncomingArrivalItem $item): bool
    {
        return (float) $item->weight_nett > 0;
    }

    /**
     * Apakah sebuah receive berasal dari item ber-basis berat (KGM)?
     * Dipakai untuk menentukan unit yang diposting ke stok.
     */
    public function usesWeightBasisItem(IncomingReceive $receive): bool
    {
        $item = $receive->arrivalItem;
        if ($item === null) {
            // Tanpa item: asumsikan KGM bila ada net_weight.
            return (float) ($receive->net_weight ?? 0) > 0;
        }

        return $this->usesWeightBasis($item);
    }

    /**
     * Total yang sudah direceive untuk satu item, sesuai basis item.
     */
    public function receivedQuantity(IncomingArrivalItem $item): float
    {
        if ($this->usesWeightBasis($item)) {
            return (float) $item->receives->sum(fn (IncomingReceive $r) => $this->postedWeight($r));
        }

        return (float) $item->receives->sum('qty');
    }

    /**
     * Total rencana (planned) untuk satu item, sesuai basis item.
     */
    public function plannedQuantity(IncomingArrivalItem $item): float
    {
        if ($this->usesWeightBasis($item)) {
            return (float) $item->weight_nett;
        }

        return (float) $item->qty_goods;
    }

    /**
     * Sisa yang bisa diterima untuk satu arrival item (basis KGM atau qty_goods).
     */
    public function remainingQty(IncomingArrivalItem $item): float
    {
        return max(0, $this->plannedQuantity($item) - $this->receivedQuantity($item));
    }

    /**
     * Whether an arrival has no pending receives / missing inspection / missing tag.
     */
    public function hasPendingReceives(IncomingArrival $arrival): bool
    {
        $arrival->loadMissing(['items.receives', 'containers.inspection']);

        foreach ($arrival->items as $item) {
            if ($this->remainingQty($item) > 0) {
                return true;
            }
        }

        foreach ($arrival->containers as $container) {
            if (! $container->inspection) {
                return true;
            }
        }

        foreach ($arrival->items as $item) {
            foreach ($item->receives as $receive) {
                if ($receive->tag === null || trim((string) $receive->tag) === '') {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Kontribusi & satuan stok untuk sebuah receive.
     *
     * Material non-berat (mis. SHEET/PCS/ROLL) diposting sebesar `qty` sesuai
     * satuannya, bukan beratnya — supaya satuan stok sama dengan `uom_rm` BOM
     * dan bisa dicocokkan saat alokasi/release. Satuan berbasis berat (KGM/KG)
     * tetap memakai berat (net_weight ?? weight ?? qty).
     *
     * @return array{0: float, 1: string}
     */
    private function stockContribution(IncomingReceive $receive): array
    {
        $unit = UomCatalog::normalize($receive->qty_unit ?? $receive->arrivalItem?->unit_goods);
        $qty = (float) $receive->qty;

        if ($unit !== null && ! UomCatalog::isWeight($unit) && $qty > 0) {
            return [$qty, $unit];
        }

        if ($this->usesWeightBasisItem($receive)) {
            return [$this->postedWeight($receive), UomCatalog::WEIGHT];
        }

        return [$this->postedWeight($receive), $unit ?? UomCatalog::PIECE];
    }

    /**
     * Post a receive into the per-part, per-tag FIFO stock ledger.
     * Setiap baris stok = satu tag receive, dengan ATA (received_at) untuk urutan FIFO.
     * Satuan stok mengikuti satuan material (lihat stockContribution).
     */
    public function postStock(IncomingReceive $receive): void
    {
        $partId = $receive->part_id ?? $receive->arrivalItem?->part_id;
        if ($partId === null) {
            return;
        }

        [$contribution, $unit] = $this->stockContribution($receive);
        if ($contribution <= 0) {
            return;
        }

        $receivedAt = $receive->ata_date ?? now();

        DB::transaction(function () use ($partId, $receive, $unit, $contribution, $receivedAt) {
            $stock = PartStock::query()
                ->where('part_id', $partId)
                ->where('tag', $receive->tag)
                ->lockForUpdate()
                ->first();

            if ($stock) {
                $stock->increment('qty', $contribution);
                // FIFO: pertahankan ATA paling awal (first-in).
                if ($stock->received_at === null || $receivedAt->lt($stock->received_at)) {
                    $stock->update(['received_at' => $receivedAt, 'receive_id' => $receive->id]);
                }
            } else {
                PartStock::create([
                    'part_id' => $partId,
                    'tag' => $receive->tag,
                    'qty' => $contribution,
                    'qty_unit' => $unit,
                    'received_at' => $receivedAt,
                    'receive_id' => $receive->id,
                    'price' => $receive->arrivalItem?->price ?? null,
                    'remarks' => 'Receive #'.$receive->id,
                ]);
            }
        });
    }

    /**
     * Reverse a receive contribution from stock (used on delete/update).
     * Wajib memakai kontribusi yang sama dengan postStock.
     */
    public function reverseStock(IncomingReceive $receive): void
    {
        $partId = $receive->part_id ?? $receive->arrivalItem?->part_id;
        if ($partId === null) {
            return;
        }

        [$contribution] = $this->stockContribution($receive);
        if ($contribution <= 0) {
            return;
        }

        DB::transaction(function () use ($partId, $receive, $contribution) {
            $stock = PartStock::query()
                ->where('part_id', $partId)
                ->where('tag', $receive->tag)
                ->lockForUpdate()
                ->first();

            if (! $stock) {
                return;
            }

            $newQty = (float) $stock->qty - $contribution;
            if ($newQty <= 0) {
                $stock->delete();
            } else {
                $stock->update(['qty' => $newQty]);
            }
        });
    }

    public function resolvePartId(IncomingArrivalItem $item): ?int
    {
        return $item->part_id;
    }

    /**
     * Qty yang sedang di-book (status booked) per part, opsional filter UOM.
     *
     * @param  iterable<int>  $partIds
     * @return array<int, float> part_id => qty booked
     */
    public function bookedQtyBatch(iterable $partIds, ?string $uom = null): array
    {
        $ids = collect($partIds)->map(fn ($id) => (int) $id)->filter()->unique()->values();

        if ($ids->isEmpty()) {
            return [];
        }

        $q = WorkOrderMaterialBooking::query()
            ->whereIn('part_id', $ids)
            ->where('status', WorkOrderMaterialBooking::STATUS_BOOKED);

        $normalized = UomCatalog::normalize($uom);
        if ($normalized !== null) {
            $q->whereRaw('UPPER(COALESCE(uom, \'\')) = ?', [$normalized]);
        }

        $totals = $q->groupBy('part_id')
            ->selectRaw('part_id, SUM(qty) AS total')
            ->pluck('total', 'part_id');

        return $ids->mapWithKeys(fn ($id) => [$id => (float) ($totals[$id] ?? 0)])->all();
    }

    /**
     * Total stok FIFO aktif untuk sebuah part, opsional difilter per UOM.
     * UOM dicocokkan case-insensitive; bila null, semua UOM dijumlahkan.
     * Qty yang sudah di-book WO lain dikurangi (belum bisa dipakai).
     */
    public function availableFifo(int $partId, ?string $uom = null): float
    {
        $stock = (float) PartStock::query()
            ->where('part_id', $partId)
            ->where('qty', '>', 0)
            ->when(UomCatalog::normalize($uom) !== null, fn ($q) => $q
                ->whereRaw('UPPER(COALESCE(qty_unit, \'\')) = ?', [UomCatalog::normalize($uom)]))
            ->sum('qty');

        $booked = $this->bookedQtyBatch([$partId], $uom)[$partId] ?? 0.0;

        return max(0.0, $stock - $booked);
    }

    /**
     * Stok FIFO untuk banyak part sekaligus dalam satu query — dipakai saat
     * menampilkan saran substitute (bisa puluhan part) agar tidak N+1.
     * Qty yang sudah di-book WO lain dikurangi.
     *
     * @param  iterable<int>  $partIds
     * @return array<int, float> part_id => qty tersedia
     */
    public function availableFifoBatch(iterable $partIds, ?string $uom = null): array
    {
        $ids = collect($partIds)->map(fn ($id) => (int) $id)->filter()->unique()->values();

        if ($ids->isEmpty()) {
            return [];
        }

        $q = PartStock::query()
            ->whereIn('part_id', $ids)
            ->where('qty', '>', 0);

        $normalized = UomCatalog::normalize($uom);
        if ($normalized !== null) {
            $q->whereRaw('UPPER(COALESCE(qty_unit, \'\')) = ?', [$normalized]);
        }

        $totals = $q->groupBy('part_id')
            ->selectRaw('part_id, SUM(qty) AS total')
            ->pluck('total', 'part_id');

        $booked = $this->bookedQtyBatch($ids, $uom);

        // Pastikan semua id hadir (0 bila tak ada stok / habis di-book).
        return $ids->mapWithKeys(fn ($id) => [
            $id => max(0.0, (float) ($totals[$id] ?? 0) - (float) ($booked[$id] ?? 0)),
        ])->all();
    }

    /**
     * Booking stok FIFO untuk sebuah item WO — stok fisik TIDAK dikurangi,
     * hanya dikunci sampai dikonsumsi saat Production Result.
     *
     * @return list<array{booking_id:int, part_stock_id:int, tag:string|null, take_qty:float, uom:string, price:float|null, invoice:string|null, supplier:string|null}>
     */
    public function bookFifoByUom(
        int $partId,
        float $qtyNeed,
        ?string $uom,
        int $workOrderId,
        int $workOrderItemId,
        ?int $actorId = null
    ): array {
        if ($qtyNeed <= 0) {
            return [];
        }

        $normalized = UomCatalog::normalize($uom);

        return DB::transaction(function () use ($partId, $qtyNeed, $uom, $normalized, $workOrderId, $workOrderItemId, $actorId) {
            $stocks = PartStock::query()
                ->where('part_id', $partId)
                ->where('qty', '>', 0)
                ->when($normalized !== null, fn ($q) => $q
                    ->whereRaw('UPPER(COALESCE(qty_unit, \'\')) = ?', [$normalized]))
                ->orderByRaw('received_at ASC NULLS LAST')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $bookedByStock = WorkOrderMaterialBooking::query()
                ->whereIn('part_stock_id', $stocks->pluck('id'))
                ->where('status', WorkOrderMaterialBooking::STATUS_BOOKED)
                ->groupBy('part_stock_id')
                ->selectRaw('part_stock_id, SUM(qty) AS total')
                ->pluck('total', 'part_stock_id');

            $result = [];
            $remaining = $qtyNeed;

            foreach ($stocks as $stock) {
                if ($remaining <= 1e-9) {
                    break;
                }

                $bookable = (float) $stock->qty - (float) ($bookedByStock[$stock->id] ?? 0);
                if ($bookable <= 1e-9) {
                    continue;
                }

                $take = min($bookable, $remaining);
                $receive = $stock->receive;

                $booking = WorkOrderMaterialBooking::create([
                    'work_order_id' => $workOrderId,
                    'work_order_item_id' => $workOrderItemId,
                    'part_id' => $partId,
                    'part_stock_id' => $stock->id,
                    'tag' => $stock->tag,
                    'qty' => $take,
                    'uom' => $stock->qty_unit,
                    'status' => WorkOrderMaterialBooking::STATUS_BOOKED,
                    'booked_at' => now(),
                    'created_by' => $actorId,
                    'updated_by' => $actorId,
                ]);

                $result[] = [
                    'booking_id' => $booking->id,
                    'part_stock_id' => $stock->id,
                    'tag' => $stock->tag,
                    'take_qty' => $take,
                    'uom' => (string) ($stock->qty_unit ?? $uom ?? ''),
                    'price' => $stock->price !== null ? (float) $stock->price : null,
                    'invoice' => $receive?->invoice_no !== null ? (string) $receive->invoice_no : null,
                    'supplier' => $receive?->arrivalItem?->arrival?->supplier?->supplier_name,
                ];

                $remaining -= $take;
            }

            return $result;
        });
    }

    /**
     * Booking dari SATU tag spesifik (scan label di mobile). Stok tidak dikurangi.
     *
     * @return array{booking_id:int, part_stock_id:int, tag:string|null, take_qty:float, remaining_stock_after:float, uom:string, price:float|null, invoice:string|null, supplier:string|null}|null
     */
    public function bookFromTag(
        string $tag,
        ?int $partId,
        ?float $qty,
        int $workOrderId,
        int $workOrderItemId,
        ?int $actorId = null
    ): ?array {
        $tag = trim($tag);
        if ($tag === '') {
            return null;
        }

        return DB::transaction(function () use ($tag, $partId, $qty, $workOrderId, $workOrderItemId, $actorId) {
            $stock = PartStock::query()
                ->whereRaw('LOWER(COALESCE(tag, \'\')) = ?', [mb_strtolower($tag)])
                ->where('qty', '>', 0)
                ->when($partId !== null, fn ($q) => $q->where('part_id', $partId))
                ->orderByRaw('received_at ASC NULLS LAST')
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if ($stock === null) {
                return null;
            }

            $booked = (float) WorkOrderMaterialBooking::query()
                ->where('part_stock_id', $stock->id)
                ->where('status', WorkOrderMaterialBooking::STATUS_BOOKED)
                ->sum('qty');

            $bookable = (float) $stock->qty - $booked;
            if ($bookable <= 1e-9) {
                return null;
            }

            $take = $qty === null ? $bookable : min($qty, $bookable);
            if ($take <= 0) {
                return null;
            }

            $receive = $stock->receive;

            $booking = WorkOrderMaterialBooking::create([
                'work_order_id' => $workOrderId,
                'work_order_item_id' => $workOrderItemId,
                'part_id' => $stock->part_id,
                'part_stock_id' => $stock->id,
                'tag' => $stock->tag,
                'qty' => $take,
                'uom' => $stock->qty_unit,
                'status' => WorkOrderMaterialBooking::STATUS_BOOKED,
                'booked_at' => now(),
                'created_by' => $actorId,
                'updated_by' => $actorId,
            ]);

            return [
                'booking_id' => $booking->id,
                'part_stock_id' => $stock->id,
                'tag' => $stock->tag,
                'take_qty' => $take,
                'remaining_stock_after' => $bookable - $take,
                'uom' => (string) ($stock->qty_unit ?? ''),
                'price' => $stock->price !== null ? (float) $stock->price : null,
                'invoice' => $receive?->invoice_no !== null ? (string) $receive->invoice_no : null,
                'supplier' => $receive?->arrivalItem?->arrival?->supplier?->supplier_name,
            ];
        });
    }

    /**
     * Konsumsi booking sebuah item WO (dipakai saat Production Result):
     * stok fisik baru berkurang di sini, booking ditandai consumed.
     *
     * @return list<array{booking_id:int, part_stock_id:int|null, tag:string|null, take_qty:float, uom:string|null}>
     */
    public function consumeBookings(
        int $workOrderItemId,
        int $partId,
        float $qtyNeed,
        ?string $uom = null,
        ?int $actorId = null
    ): array {
        if ($qtyNeed <= 0) {
            return [];
        }

        $normalized = UomCatalog::normalize($uom);

        return DB::transaction(function () use ($workOrderItemId, $partId, $qtyNeed, $normalized, $actorId) {
            $bookings = WorkOrderMaterialBooking::query()
                ->where('work_order_item_id', $workOrderItemId)
                ->where('part_id', $partId)
                ->where('status', WorkOrderMaterialBooking::STATUS_BOOKED)
                ->when($normalized !== null, fn ($q) => $q
                    ->whereRaw('UPPER(COALESCE(uom, \'\')) = ?', [$normalized]))
                ->orderBy('booked_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $result = [];
            $remaining = $qtyNeed;

            foreach ($bookings as $booking) {
                if ($remaining <= 1e-9) {
                    break;
                }

                $take = min((float) $booking->qty, $remaining);

                if ($booking->part_stock_id !== null) {
                    $stock = PartStock::query()->lockForUpdate()->find($booking->part_stock_id);
                    if ($stock !== null) {
                        $newQty = (float) $stock->qty - $take;
                        if ($newQty <= 1e-9) {
                            $stock->delete();
                        } else {
                            $stock->update(['qty' => $newQty]);
                        }
                    }
                }

                $bookingRemaining = (float) $booking->qty - $take;
                if ($bookingRemaining <= 1e-9) {
                    $booking->update([
                        'status' => WorkOrderMaterialBooking::STATUS_CONSUMED,
                        'consumed_at' => now(),
                        'updated_by' => $actorId,
                    ]);
                } else {
                    $booking->update(['qty' => $bookingRemaining, 'updated_by' => $actorId]);
                }

                $result[] = [
                    'booking_id' => (int) $booking->id,
                    'part_stock_id' => $booking->part_stock_id !== null ? (int) $booking->part_stock_id : null,
                    'tag' => $booking->tag,
                    'take_qty' => $take,
                    'uom' => $booking->uom,
                ];

                $remaining -= $take;
            }

            return $result;
        });
    }

    /**
     * Qty booking aktif untuk satu item WO + part.
     */
    public function bookedQtyForItem(int $workOrderItemId, int $partId, ?string $uom = null): float
    {
        $q = WorkOrderMaterialBooking::query()
            ->where('work_order_item_id', $workOrderItemId)
            ->where('part_id', $partId)
            ->where('status', WorkOrderMaterialBooking::STATUS_BOOKED);

        $normalized = UomCatalog::normalize($uom);
        if ($normalized !== null) {
            $q->whereRaw('UPPER(COALESCE(uom, \'\')) = ?', [$normalized]);
        }

        return (float) $q->sum('qty');
    }

    /**
     * Konsumsi material untuk sebuah item WO: habiskan booking item ini dulu
     * (stok fisik berkurang di sini), sisanya ambil dari stok bebas FIFO
     * (mis. WO lama yang belum punya booking).
     *
     * @return list<array{booking_id:int|null, part_stock_id:int|null, tag:string|null, take_qty:float, uom:string|null, from_booking:bool}>
     */
    public function consumeForItem(
        int $workOrderItemId,
        int $partId,
        float $qtyNeed,
        ?string $uom = null,
        ?int $actorId = null
    ): array {
        if ($qtyNeed <= 0) {
            return [];
        }

        $result = [];
        $remaining = $qtyNeed;

        foreach ($this->consumeBookings($workOrderItemId, $partId, $remaining, $uom, $actorId) as $alloc) {
            $result[] = [
                'booking_id' => $alloc['booking_id'],
                'part_stock_id' => $alloc['part_stock_id'],
                'tag' => $alloc['tag'],
                'take_qty' => (float) $alloc['take_qty'],
                'uom' => $alloc['uom'],
                'from_booking' => true,
            ];
            $remaining -= (float) $alloc['take_qty'];
        }

        if ($remaining > 1e-9) {
            foreach ($this->consumeFifoByUom($partId, $remaining, $uom) as $alloc) {
                $result[] = [
                    'booking_id' => null,
                    'part_stock_id' => $alloc['part_stock_id'],
                    'tag' => $alloc['tag'],
                    'take_qty' => (float) $alloc['take_qty'],
                    'uom' => $alloc['uom'],
                    'from_booking' => false,
                ];
            }
        }

        return $result;
    }

    /**
     * Lepas booking yang masih aktif (WO dibatalkan / dihapus).
     */
    public function releaseBookings(int $workOrderId, ?int $workOrderItemId = null, ?int $actorId = null): int
    {
        return WorkOrderMaterialBooking::query()
            ->where('work_order_id', $workOrderId)
            ->when($workOrderItemId !== null, fn ($q) => $q->where('work_order_item_id', $workOrderItemId))
            ->where('status', WorkOrderMaterialBooking::STATUS_BOOKED)
            ->update([
                'status' => WorkOrderMaterialBooking::STATUS_RELEASED,
                'updated_by' => $actorId,
                'updated_at' => now(),
            ]);
    }

    /**
     * Consume FIFO generic (by UOM) — tertua (received_at ASC NULLS LAST, lalu id) didahulukan.
     * Return list alokasi: [{ part_stock_id, tag, take_qty, remaining_stock_after, uom }].
     * Dipakai WO / Material Allocation untuk UOM apa pun (KGM/SHEET/ROLL/PCS).
     */
    public function consumeFifoByUom(int $partId, float $qtyNeed, ?string $uom = null): array
    {
        if ($qtyNeed <= 0) {
            return [];
        }

        $result = [];

        DB::transaction(function () use ($partId, $qtyNeed, $uom, &$result) {
            $stocksQ = PartStock::query()
                ->where('part_id', $partId)
                ->where('qty', '>', 0)
                ->orderByRaw('received_at ASC NULLS LAST')
                ->orderBy('id');

            if (UomCatalog::normalize($uom) !== null) {
                $stocksQ->whereRaw('UPPER(COALESCE(qty_unit, \'\')) = ?', [UomCatalog::normalize($uom)]);
            }

            $stocks = $stocksQ->lockForUpdate()->get();

            // Sisakan qty yang sedang di-book WO lain — jangan dicuri.
            $bookedByStock = WorkOrderMaterialBooking::query()
                ->whereIn('part_stock_id', $stocks->pluck('id'))
                ->where('status', WorkOrderMaterialBooking::STATUS_BOOKED)
                ->groupBy('part_stock_id')
                ->selectRaw('part_stock_id, SUM(qty) AS total')
                ->pluck('total', 'part_stock_id');

            $remainingNeed = $qtyNeed;
            foreach ($stocks as $stock) {
                if ($remainingNeed <= 1e-9) {
                    break;
                }

                $free = (float) $stock->qty - (float) ($bookedByStock[$stock->id] ?? 0);
                if ($free <= 1e-9) {
                    continue;
                }

                $take = min($free, $remainingNeed);
                $newQty = (float) $stock->qty - $take;

                if ($newQty <= 1e-9) {
                    $stock->delete();
                    $stockId = $stock->id;
                    $newQty = 0;
                } else {
                    $stock->update(['qty' => $newQty]);
                    $stockId = $stock->id;
                }

                $result[] = [
                    'part_stock_id' => $stockId,
                    'tag' => $stock->tag,
                    'take_qty' => $take,
                    'remaining_stock_after' => $newQty,
                    'uom' => (string) ($stock->qty_unit ?? $uom ?? ''),
                ];

                $remainingNeed -= $take;
            }
        });

        return $result;
    }

    /**
     * Post stok hasil produksi (WO output WIP/FG).
     * receive_id = null, tag produksi bebas, received_at = waktu produksi.
     */
    public function postProductionStock(int $partId, string $tag, float $qty, ?string $uom = null, $receivedAt = null): PartStock
    {
        $receivedAt = $receivedAt ?? now();
        $uom = UomCatalog::normalize($uom) ?? UomCatalog::PIECE;

        return DB::transaction(function () use ($partId, $tag, $qty, $uom, $receivedAt) {
            $stock = PartStock::query()
                ->where('part_id', $partId)
                ->whereRaw('LOWER(COALESCE(tag, \'\')) = ?', [strtolower($tag)])
                ->lockForUpdate()
                ->first();

            if ($stock) {
                $stock->increment('qty', $qty);

                return $stock->fresh();
            }

            return PartStock::create([
                'part_id' => $partId,
                'tag' => $tag,
                'qty' => $qty,
                'qty_unit' => $uom,
                'received_at' => $receivedAt,
                'receive_id' => null,
                'price' => null,
                'remarks' => 'WO output',
            ]);
        });
    }
}
