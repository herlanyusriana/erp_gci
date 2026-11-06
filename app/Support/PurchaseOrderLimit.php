<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\IncomingArrivalItem;
use App\Models\IncomingReceive;
use App\Models\PurchaseOrderItem;

/**
 * Batas kuantitas satu baris PO (PO + part).
 *
 * Total kedatangan maupun total penerimaan tidak boleh melebihi qty yang
 * dipesan, berapa pun jumlah dokumen kedatangan yang dibuat untuk PO itu.
 * Perbandingan memakai basis item: berat bila diisi, selain itu qty barang.
 */
final class PurchaseOrderLimit
{
    /**
     * Baris PO untuk kombinasi PO + part, atau null bila tidak terhubung.
     */
    public static function poItem(?int $purchaseOrderId, ?int $partId): ?PurchaseOrderItem
    {
        if ($purchaseOrderId === null || $partId === null) {
            return null;
        }

        return PurchaseOrderItem::query()
            ->where('purchase_order_id', $purchaseOrderId)
            ->where('part_id', $partId)
            ->first();
    }

    /**
     * Kuantitas & satuan pembanding satu item kedatangan.
     *
     * @return array{0: float, 1: string}|null null bila satuannya tidak diketahui
     */
    public static function arrivalBasis(
        ?float $qtyGoods,
        ?string $unitGoods,
        ?float $weightNett,
        ?string $unitWeight,
    ): ?array {
        if ((float) $weightNett > 0) {
            return [(float) $weightNett, UomCatalog::normalize($unitWeight) ?? UomCatalog::WEIGHT];
        }

        $unit = UomCatalog::normalize($unitGoods);

        return $unit === null ? null : [(float) $qtyGoods, $unit];
    }

    /**
     * Kuantitas & satuan pembanding satu penerimaan.
     *
     * @return array{0: float, 1: string}|null
     */
    public static function receiveBasis(IncomingReceive $receive): ?array
    {
        return self::arrivalBasis(
            (float) $receive->qty,
            $receive->qty_unit,
            $receive->net_weight,
            $receive->arrivalItem?->unit_weight,
        );
    }

    /**
     * Sisa qty PO yang masih boleh dikedatangkan untuk satu baris PO.
     *
     * @return array{po_qty: float, remaining: float}|null null bila item tidak
     *                                                     terhubung ke baris PO atau satuannya berbeda
     */
    public static function arrivalLimit(
        int $purchaseOrderId,
        int $partId,
        string $unit,
        ?int $excludeArrivalItemId = null,
    ): ?array {
        $poItem = self::poItem($purchaseOrderId, $partId);
        if ($poItem === null || UomCatalog::normalize($poItem->unit) !== $unit) {
            return null;
        }

        $poQty = (float) $poItem->qty;

        return [
            'po_qty' => $poQty,
            'remaining' => max(0.0, $poQty - self::arrivedQty($purchaseOrderId, $partId, $unit, $excludeArrivalItemId)),
        ];
    }

    /**
     * Sisa qty PO yang masih boleh diterima untuk satu item kedatangan.
     *
     * @return array{po_qty: float, remaining: float}|null
     */
    public static function receiveLimit(IncomingArrivalItem $item, ?int $excludeReceiveId = null): ?array
    {
        $purchaseOrderId = $item->arrival?->purchase_order_id;
        $partId = $item->part_id;

        if ($purchaseOrderId === null || $partId === null) {
            return null;
        }

        $basis = self::arrivalBasis($item->qty_goods, $item->unit_goods, $item->weight_nett, $item->unit_weight);
        if ($basis === null) {
            return null;
        }

        $unit = $basis[1];
        $poItem = self::poItem((int) $purchaseOrderId, (int) $partId);
        if ($poItem === null || UomCatalog::normalize($poItem->unit) !== $unit) {
            return null;
        }

        $poQty = (float) $poItem->qty;

        return [
            'po_qty' => $poQty,
            'remaining' => max(0.0, $poQty - self::receivedQty((int) $purchaseOrderId, (int) $partId, $unit, $excludeReceiveId)),
        ];
    }

    /** Total kuantitas kedatangan pada satu baris PO, dalam satuan tertentu. */
    public static function arrivedQty(
        int $purchaseOrderId,
        int $partId,
        string $unit,
        ?int $excludeArrivalItemId = null,
    ): float {
        $items = IncomingArrivalItem::query()
            ->with('arrival:id,purchase_order_id')
            ->where('part_id', $partId)
            ->whereHas('arrival', fn ($query) => $query->where('purchase_order_id', $purchaseOrderId))
            ->when($excludeArrivalItemId !== null, fn ($query) => $query->whereKeyNot($excludeArrivalItemId))
            ->get();

        $total = 0.0;

        foreach ($items as $item) {
            $basis = self::arrivalBasis($item->qty_goods, $item->unit_goods, $item->weight_nett, $item->unit_weight);

            if ($basis !== null && $basis[1] === $unit) {
                $total += $basis[0];
            }
        }

        return $total;
    }

    /** Total kuantitas yang sudah diterima ke stok pada satu baris PO. */
    public static function receivedQty(
        int $purchaseOrderId,
        int $partId,
        string $unit,
        ?int $excludeReceiveId = null,
    ): float {
        $receives = IncomingReceive::query()
            ->with('arrivalItem:id,arrival_id,part_id,unit_weight')
            ->whereHas('arrivalItem', fn ($query) => $query
                ->where('part_id', $partId)
                ->whereHas('arrival', fn ($arrival) => $arrival->where('purchase_order_id', $purchaseOrderId)))
            ->when($excludeReceiveId !== null, fn ($query) => $query->whereKeyNot($excludeReceiveId))
            ->get();

        $total = 0.0;

        foreach ($receives as $receive) {
            $basis = self::receiveBasis($receive);

            if ($basis !== null && $basis[1] === $unit) {
                $total += $basis[0];
            }
        }

        return $total;
    }
}
