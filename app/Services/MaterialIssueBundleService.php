<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\IncomingReceive;
use App\Models\PartStock;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use App\Support\BundlePacking;
use App\Support\UomCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Dasar angka untuk layar issue material setelah operator memindai satu tag:
 * kebutuhan bersih item WO, isi tiap bundle pada tag, dan qty yang otomatis
 * dicentang. Aturan hitungnya ada di {@see BundlePacking}.
 */
class MaterialIssueBundleService
{
    /** Batas jumlah bundle yang dirender per tag agar layar tetap ringan. */
    public const MAX_BUNDLES = 100;

    public function __construct(private WoService $woService) {}

    /**
     * Ringkasan satu tag untuk satu item WO.
     *
     * @return array<string, mixed>
     *
     * @throws ValidationException bila tag tidak berstok
     */
    public function forTag(WorkOrderItem $item, WorkOrder $workOrder, string $tag): array
    {
        $stock = PartStock::query()
            ->with(['receive:id,invoice_no,arrival_item_id,bundle_count,bundle_qty,bundle_unit,packing_count,packing_type,packing_qty', 'receive.arrivalItem:id,arrival_id,part_id,material_group,size,unit_goods'])
            ->whereRaw('LOWER(COALESCE(tag, \'\')) = ?', [mb_strtolower(trim($tag))])
            ->where('qty', '>', 0)
            ->whereIn('part_id', $this->allowedPartIds($item))
            ->orderByRaw('received_at ASC NULLS LAST')
            ->orderBy('id')
            ->first();

        if ($stock === null) {
            throw ValidationException::withMessages([
                'tag' => __('Tag tidak ditemukan atau bukan material untuk item ini.'),
            ]);
        }

        return $this->build($item, $workOrder, $stock);
    }

    /**
     * Versi tanpa exception untuk endpoint yang menerima part_stock_id langsung.
     *
     * @return array<string, mixed>|null
     */
    public function forStock(WorkOrderItem $item, WorkOrder $workOrder, int $partStockId): ?array
    {
        $stock = PartStock::query()
            ->with(['receive:id,invoice_no,arrival_item_id,bundle_count,bundle_qty,bundle_unit,packing_count,packing_type,packing_qty', 'receive.arrivalItem:id,arrival_id,part_id,material_group,size,unit_goods'])
            ->whereKey($partStockId)
            ->where('qty', '>', 0)
            ->first();

        if ($stock === null || ! in_array((int) $stock->part_id, $this->allowedPartIds($item), true)) {
            return null;
        }

        return $this->build($item, $workOrder, $stock);
    }

    /**
     * Qty yang sudah di-issue untuk satu item WO (seluruh slip posted).
     */
    public function issuedQty(WorkOrderItem $item): float
    {
        return (float) DB::table('material_issue_items as mii')
            ->join('material_issues as mi', 'mi.id', '=', 'mii.material_issue_id')
            ->where('mi.status', 'posted')
            ->where('mii.work_order_item_id', $item->id)
            ->sum('mii.qty');
    }

    /**
     * Stok material yang sudah berada di mesin/WIP untuk WO ini.
     *
     * Asumsi: material yang sudah di-issue ke WO ini tetapi belum dikonsumsi
     * oleh Production Result masih berada di lantai produksi.
     */
    public function stockAtMachine(WorkOrderItem $item): float
    {
        $issued = $this->issuedQty($item);

        if ($issued <= 1e-9) {
            return 0.0;
        }

        $consumed = (float) DB::table('work_order_consumptions')
            ->where('work_order_id', $item->work_order_id)
            ->where('part_id', $item->child_part_id)
            ->sum('qty');

        return round(max(0.0, $issued - $consumed), 4);
    }

    /**
     * Kebutuhan bersih item WO.
     */
    public function netRequirement(WorkOrderItem $item): array
    {
        $required = $this->requiredQty($item);
        $issued = $this->issuedQty($item);
        $stock = $this->stockAtMachine($item);

        // Yang mengurangi kebutuhan hanyalah material yang masih berada di mesin.
        // Qty yang sudah terpakai produksi tidak boleh dihitung dua kali.
        return [
            'required_qty' => $required,
            'issued_qty' => round($issued, 4),
            'stock_at_machine' => $stock,
            'net_requirement' => BundlePacking::netRequirement($required, $stock),
        ];
    }

    /**
     * Qty yang masih boleh di-issue untuk item ini.
     */
    public function remainingQty(WorkOrderItem $item): float
    {
        return BundlePacking::netRequirement($this->requiredQty($item), $this->stockAtMachine($item));
    }

    /**
     * Jumlah kemasan individual yang dapat dipilih pada satu tag penerimaan.
     * Bila terdapat sub-kemasan bertingkat (misal 2 Pallet @ 5 Box), jumlahnya
     * adalah total sub-kemasannya (10 Box).
     */
    public function bundleCount(PartStock $stock): int
    {
        $receive = $stock->receive;

        $bCount = (float) ($receive?->bundle_count ?? $receive?->bundle_qty ?? 0);
        $pCount = (float) ($receive?->packing_count ?? 1);

        if ($bCount > 0) {
            $totalPackages = (int) round($bCount * max(1.0, $pCount));

            return (int) min(max(1, $totalPackages), self::MAX_BUNDLES);
        }

        return 1;
    }

    /**
     * @return array<string, mixed>
     */
    private function build(WorkOrderItem $item, WorkOrder $workOrder, PartStock $stock): array
    {
        $uom = UomCatalog::normalize((string) $stock->qty_unit)
            ?? UomCatalog::normalize((string) $item->uom_rm);
        $totalQty = (float) $stock->qty;
        $bundleCount = $this->bundleCount($stock);
        $net = $this->netRequirement($item);
        $receive = $stock->receive;
        $arrivalItem = $receive?->arrivalItem;

        // Satuan kemasan individual yang dicentang: pakai jenis packing dalam jika ada (mis. BOX),
        // fallback ke bundle_unit (mis. PALLET / BUNDLE)
        $itemPackingUnit = $receive?->packing_type ?: ($receive?->bundle_unit ?: 'bundle');

        // `net_requirement` sudah memperhitungkan issued + stok di mesin; jangan
        // kurangi dua kali.
        $plan = BundlePacking::plan(
            $net['required_qty'],
            $net['stock_at_machine'],
            $totalQty,
            $bundleCount,
        );

        return [
            'tag' => $stock->tag,
            'part_stock_id' => (int) $stock->id,
            'part_id' => (int) $stock->part_id,
            'part_number' => $arrivalItem?->part?->part_number,
            'part_name' => $arrivalItem?->part?->part_name,
            // Nama material yang ditampilkan ke operator: kelompok material impor,
            // jatuh ke nama part bila tidak diisi.
            'material_group' => $arrivalItem?->material_group ?: $arrivalItem?->display_part_name,
            'size' => $arrivalItem?->size,
            'invoice_no' => $receive?->invoice_no,
            'uom' => $uom,
            'total_qty' => round($totalQty, 4),
            'bundle_unit' => $itemPackingUnit,
            'bundle_count' => $plan['bundle_count'],
            'packing_qty' => $plan['packing_qty'],
            'packaging_text' => $this->packagingSummary($receive),
            'bundles' => $plan['bundles'],
            'net_requirement' => $plan['net_requirement'],
            'required_qty' => $net['required_qty'],
            'issued_qty' => $net['issued_qty'],
            'stock_at_machine' => $net['stock_at_machine'],
            'bundle_qty' => $plan['bundle_qty'],
            'total_issue' => $plan['total_qty'],
            'work_order' => [
                'id' => (int) $workOrder->id,
                'wo_no' => $workOrder->wo_no,
                'qty' => (float) $workOrder->qty,
            ],
            'work_order_item_id' => (int) $item->id,
        ];
    }

    private function packagingSummary(?IncomingReceive $receive): ?string
    {
        if ($receive === null) {
            return null;
        }

        $bCount = $receive->bundle_count ?? $receive->bundle_qty;
        $bUnit = $receive->bundle_unit ?? 'PALLET';
        $pCount = $receive->packing_count ?? 1;
        $pType = $receive->packing_type ?? 'BOX';

        if ($bCount && $pCount && $pCount > 1) {
            return "{$bCount} {$bUnit} @ {$pCount} {$pType}";
        }

        return $bCount ? "{$bCount} {$bUnit}" : null;
    }

    private function requiredQty(WorkOrderItem $item): float
    {
        return round((float) $item->qty_required, 4);
    }

    /**
     * Part yang sah untuk item ini: main material BOM, substitute aktif, dan
     * part yang sudah dialokasikan pada item.
     *
     * @return list<int>
     */
    private function allowedPartIds(WorkOrderItem $item): array
    {
        // Aturan main material + substitute + alokasi sudah ada di WoService;
        // jangan menulis daftar kedua yang bisa menyimpang.
        return $this->woService->allowedPartIdsForItem($item);
    }
}
