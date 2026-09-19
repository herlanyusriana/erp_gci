<?php

namespace App\Services;

use App\Models\IncomingArrival;
use App\Models\IncomingArrivalItem;
use App\Models\IncomingReceive;
use App\Models\PartStock;
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
     * Post a receive into the per-part, per-tag FIFO stock ledger (KGM basis).
     * Setiap baris stok = satu tag receive, dengan ATA (received_at) untuk urutan FIFO.
     */
    public function postStock(IncomingReceive $receive): void
    {
        $partId = $receive->part_id ?? $receive->arrivalItem?->part_id;
        if ($partId === null) {
            return;
        }

        $contribution = $this->postedWeight($receive);
        if ($contribution <= 0) {
            return;
        }

        // Unit stok: KGM bila item ber-basis berat; selain itu unit barang item.
        $unit = $this->usesWeightBasisItem($receive)
            ? UomCatalog::WEIGHT
            : UomCatalog::normalize($receive->qty_unit ?? $receive->arrivalItem?->unit_goods) ?? UomCatalog::PIECE;
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
     * Reverse a receive contribution from stock (used on delete/update), KGM basis.
     */
    public function reverseStock(IncomingReceive $receive): void
    {
        $partId = $receive->part_id ?? $receive->arrivalItem?->part_id;
        if ($partId === null) {
            return;
        }

        $contribution = $this->postedWeight($receive);
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

    /**
     * Consume KGM dari stok FIFO per part — tertua (ATA paling awal) didahulukan.
     * Return list alokasi: [{ receive_id, tag, take_kgm, remaining_stock_after }].
     * Dipakai oleh Material Allocation / WO.
     */
    public function consumeFifo(int $partId, float $kgmNeed): array
    {
        if ($kgmNeed <= 0) {
            return [];
        }

        $result = [];

        DB::transaction(function () use ($partId, $kgmNeed, &$result) {
            $stocks = PartStock::query()
                ->where('part_id', $partId)
                ->where('qty', '>', 0)
                ->orderByRaw('received_at ASC NULLS LAST')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $remainingNeed = $kgmNeed;

            foreach ($stocks as $stock) {
                if ($remainingNeed <= 1e-9) {
                    break;
                }

                $take = min((float) $stock->qty, $remainingNeed);
                $newQty = (float) $stock->qty - $take;
                if ($newQty <= 1e-9) {
                    $stock->delete();
                    $newQty = 0;
                } else {
                    $stock->update(['qty' => $newQty]);
                }

                $result[] = [
                    'receive_id' => $stock->receive_id,
                    'tag' => $stock->tag,
                    'take_kgm' => $take,
                    'remaining_stock_after' => $newQty,
                ];

                $remainingNeed -= $take;
            }
        });

        return $result;
    }

    public function resolvePartId(IncomingArrivalItem $item): ?int
    {
        return $item->part_id;
    }

    /**
     * Total stok FIFO aktif untuk sebuah part, opsional difilter per UOM.
     * UOM dicocokkan case-insensitive; bila null, semua UOM dijumlahkan.
     */
    public function availableFifo(int $partId, ?string $uom = null): float
    {
        $q = PartStock::query()
            ->where('part_id', $partId)
            ->where('qty', '>', 0);

        $normalized = UomCatalog::normalize($uom);
        if ($normalized !== null) {
            $q->whereRaw('UPPER(COALESCE(qty_unit, \'\')) = ?', [$normalized]);
        }

        return (float) $q->sum('qty');
    }

    /**
     * Stok FIFO untuk banyak part sekaligus dalam satu query — dipakai saat
     * menampilkan saran substitute (bisa puluhan part) agar tidak N+1.
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

        // Pastikan semua id hadir (0 bila tak ada stok).
        return $ids->mapWithKeys(fn ($id) => [$id => (float) ($totals[$id] ?? 0)])->all();
    }

    /**
     * Consume dari SATU tag stok spesifik (dipakai scan label di mobile).
     * Berbeda dari consumeFifoByUom yang menyusuri FIFO lintas tag.
     *
     * @return array{part_stock_id:int, tag:string|null, take_qty:float, remaining_stock_after:float, uom:string, price:float|null, invoice:string|null, supplier:string|null}|null
     */
    public function consumeFromTag(string $tag, ?int $partId, ?float $qty = null): ?array
    {
        $tag = trim($tag);
        if ($tag === '') {
            return null;
        }

        return DB::transaction(function () use ($tag, $partId, $qty) {
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

            // Ambil asal material sebelum baris stok mungkin terhapus.
            $receive = $stock->receive;
            $invoice = $receive?->invoice_no;
            $supplier = $receive?->arrivalItem?->arrival?->supplier?->supplier_name;

            $available = (float) $stock->qty;
            $take = $qty === null ? $available : min($qty, $available);
            if ($take <= 0) {
                return null;
            }

            $newQty = $available - $take;
            $stockId = $stock->id;
            if ($newQty <= 1e-9) {
                $stock->delete();
                $newQty = 0.0;
            } else {
                $stock->update(['qty' => $newQty]);
            }

            return [
                'part_stock_id' => $stockId,
                'tag' => $stock->tag,
                'take_qty' => $take,
                'remaining_stock_after' => $newQty,
                'uom' => (string) ($stock->qty_unit ?? ''),
                'price' => $stock->price !== null ? (float) $stock->price : null,
                'invoice' => $invoice !== null ? (string) $invoice : null,
                'supplier' => $supplier !== null ? (string) $supplier : null,
            ];
        });
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

            $remainingNeed = $qtyNeed;
            foreach ($stocks as $stock) {
                if ($remainingNeed <= 1e-9) {
                    break;
                }

                $take = min((float) $stock->qty, $remainingNeed);
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
