<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\IncomingReceive;
use App\Models\MaterialIssueItem;
use App\Models\PartStock;
use App\Models\ProductionMaterialReceipt;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use App\Models\WorkOrderMaterialBooking;
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

    public function __construct(
        private WoService $woService,
        private DailyScheduleService $schedule,
    ) {}

    /**
     * Ringkasan satu tag untuk satu item WO.
     *
     * @return array<string, mixed>
     *
     * @throws ValidationException bila tag tidak berstok
     */
    public function forTag(WorkOrderItem $item, WorkOrder $workOrder, string $tag): array
    {
        if ($this->remainingQty($item, $workOrder) <= 1e-9) {
            throw ValidationException::withMessages([
                'tag' => __('Kebutuhan item ini sudah terpenuhi.'),
            ]);
        }

        $candidates = PartStock::query()
            ->with(['receive:id,invoice_no,arrival_item_id,bundle_count,bundle_qty,bundle_unit,packing_count,packing_type,packing_qty', 'receive.packings', 'receive.arrivalItem:id,arrival_id,part_id,material_group,size,unit_goods'])
            ->whereRaw('LOWER(COALESCE(tag, \'\')) = ?', [mb_strtolower(trim($tag))])
            ->where('qty', '>', 0)
            ->whereIn('part_id', $this->allowedPartIds($item))
            ->orderByRaw('received_at ASC NULLS LAST')
            ->orderBy('id')
            ->get();

        $stock = $candidates->first(
            fn (PartStock $candidate): bool => $this->availableQtyForItem($candidate, $workOrder, $item) > 1e-9,
        );

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
        if ($this->remainingQty($item, $workOrder) <= 1e-9) {
            return null;
        }

        $stock = PartStock::query()
            ->with(['receive:id,invoice_no,arrival_item_id,bundle_count,bundle_qty,bundle_unit,packing_count,packing_type,packing_qty', 'receive.packings', 'receive.arrivalItem:id,arrival_id,part_id,material_group,size,unit_goods'])
            ->whereKey($partStockId)
            ->where('qty', '>', 0)
            ->first();

        if ($stock === null
            || ! in_array((int) $stock->part_id, $this->allowedPartIds($item), true)
            || $this->availableQtyForItem($stock, $workOrder, $item) <= 1e-9
        ) {
            return null;
        }

        return $this->build($item, $workOrder, $stock);
    }

    /**
     * Sisa stok sebuah tag yang masih bisa di-issue untuk item WO ini
     * (memperhitungkan booking aktif, kecuali booking web milik item ini yang belum di-issue).
     */
    private function availableQtyForItem(PartStock $stock, WorkOrder $workOrder, WorkOrderItem $item): float
    {
        $booked = (float) WorkOrderMaterialBooking::query()
            ->where('part_stock_id', $stock->id)
            ->where('status', WorkOrderMaterialBooking::STATUS_BOOKED)
            ->sum('qty');

        $ownBooked = (float) WorkOrderMaterialBooking::query()
            ->where('part_stock_id', $stock->id)
            ->where('work_order_id', $workOrder->id)
            ->where('work_order_item_id', $item->id)
            ->where('status', WorkOrderMaterialBooking::STATUS_BOOKED)
            ->sum('qty');

        $pendingIssued = (float) MaterialIssueItem::query()
            ->where('part_stock_id', $stock->id)
            ->where('work_order_item_id', $item->id)
            ->whereHas('materialIssue', fn ($q) => $q->where('work_order_id', $workOrder->id)->where('status', 'posted'))
            ->whereNotIn('id', ProductionMaterialReceipt::query()->select('material_issue_item_id'))
            ->sum('qty');

        $unissuedOwnBooking = max(0.0, $ownBooked - $pendingIssued);

        return max(0.0, (float) $stock->qty - $booked + $unissuedOwnBooking);
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
            ->where(function ($q) use ($item) {
                $q->where('work_order_item_id', $item->id)
                    ->orWhere(function ($sub) use ($item) {
                        $sub->whereNull('work_order_item_id')
                            ->where('part_id', $item->child_part_id);
                    });
            })
            ->sum('qty');

        return round(max(0.0, $issued - $consumed), 4);
    }

    /**
     * Kebutuhan harian item WO berdasarkan `cons BOM × qty Plan hari ini`.
     * Bila WO tidak ada pada jadwal hari ini, jatuh ke `qty_required` total WO.
     *
     * @return array{
     *     has_daily_plan: bool,
     *     planned_qty: float|null,
     *     cons_per_unit: float,
     *     plan_required: float,
     *     wo_required: float,
     *     effective_required: float
     * }
     */
    public function planRequirement(WorkOrderItem $item, ?WorkOrder $workOrder = null): array
    {
        $wo = $workOrder ?? $item->workOrder;
        $woQty = (float) ($wo?->qty ?? 0);
        $woRequired = round((float) $item->qty_required, 4);

        $parentQty = (float) ($item->parent_qty ?? 1);
        $rawRatio = $parentQty > 0
            ? ((float) ($item->child_qty ?? 0) / $parentQty)
            : (float) ($item->child_qty ?? 0);
        $consPerUnit = ($woQty > 1e-9 && $woRequired > 1e-9)
            ? round($woRequired / $woQty, 6)
            : round($rawRatio, 6);

        $plannedQty = null;
        if ($wo !== null) {
            $today = $this->schedule->plantToday();
            $scheduled = $this->schedule->scheduledQuantities($today)
                ->where('ppi.work_order_id', $wo->id)
                ->first();
            if ($scheduled !== null && (float) $scheduled->planned_qty > 1e-9) {
                $plannedQty = round((float) $scheduled->planned_qty, 4);
            }
        }

        if ($plannedQty !== null) {
            $planRequired = round($consPerUnit * $plannedQty, 4);

            return [
                'has_daily_plan' => true,
                'planned_qty' => $plannedQty,
                'cons_per_unit' => $consPerUnit,
                'plan_required' => $planRequired,
                'wo_required' => $woRequired,
                'effective_required' => $planRequired,
            ];
        }

        return [
            'has_daily_plan' => false,
            'planned_qty' => null,
            'cons_per_unit' => $consPerUnit,
            'plan_required' => $woRequired,
            'wo_required' => $woRequired,
            'effective_required' => $woRequired,
        ];
    }

    /**
     * Sisa surplus material kompatibel dari WO lain di lantai produksi (baik yang
     * sudah diterima di mesin maupun yang baru saja di-issue gudang dan masih
     * in-transit), termasuk lintas mesin.
     *
     * Mengurangi klaim dari WO aktif lain yang di-release memakai sisa lantai
     * produksi (tanpa bon issue/booking sendiri) agar tidak terjadi double-claim.
     *
     * @return array{qty: float, packings: list<string>}
     */
    public function sharedMachineSurplusDetails(WorkOrderItem $item, ?WorkOrder $workOrder = null): array
    {
        $allowedPartIds = $this->allowedPartIds($item);
        if ($allowedPartIds === []) {
            return ['qty' => 0.0, 'packings' => []];
        }

        $uom = UomCatalog::normalize((string) $item->uom_rm);
        $woId = (int) ($workOrder?->id ?? $item->work_order_id);

        $receipts = ProductionMaterialReceipt::query()
            ->with([
                'workOrder:id,wo_no,status',
                'workOrderItem:id,qty_required,qty_consumed',
                'materialIssueItem:id,packing_nos',
            ])
            ->where('transfer_status', 'transferred')
            ->where('work_order_id', '!=', $woId)
            ->whereIn('part_id', $allowedPartIds)
            ->when($uom !== null, fn ($q) => $q->where('uom', $uom))
            ->whereColumn('qty', '>', 'qty_consumed')
            ->get();

        $inTransitIssues = MaterialIssueItem::query()
            ->with([
                'materialIssue:id,work_order_id,issue_no,status',
                'materialIssue.workOrder:id,wo_no,status',
                'workOrderItem:id,qty_required,qty_consumed',
            ])
            ->where('qty', '>', 0)
            ->whereIn('part_id', $allowedPartIds)
            ->when($uom !== null, fn ($q) => $q->where('uom', $uom))
            ->whereNotIn('id', ProductionMaterialReceipt::query()->select('material_issue_item_id'))
            ->whereHas('materialIssue', fn ($q) => $q
                ->where('status', 'posted')
                ->where('work_order_id', '!=', $woId)
                ->whereHas('workOrder', fn ($wo) => $wo->whereIn('status', ['released', 'in_progress'])))
            ->get();

        if ($receipts->isEmpty() && $inTransitIssues->isEmpty()) {
            return ['qty' => 0.0, 'packings' => []];
        }

        $supplyByKey = [];

        foreach ($receipts as $receipt) {
            $balance = max(0.0, (float) $receipt->qty - (float) $receipt->qty_consumed);
            if ($balance <= 1e-9) {
                continue;
            }
            $key = $receipt->work_order_id.'|'.($receipt->work_order_item_id ?? 0);
            if (! isset($supplyByKey[$key])) {
                $supplyByKey[$key] = [
                    'balance' => 0.0,
                    'status' => $receipt->workOrder?->status,
                    'wo_no' => $receipt->workOrder?->wo_no,
                    'item' => $receipt->workOrderItem,
                    'same_machine' => false,
                    'packings' => [],
                ];
            }
            $supplyByKey[$key]['balance'] += $balance;
            if ($item->machine_id !== null && (int) $receipt->machine_id === (int) $item->machine_id) {
                $supplyByKey[$key]['same_machine'] = true;
            }
            foreach ((array) ($receipt->materialIssueItem?->packing_nos ?? []) as $pNo) {
                $pNo = trim((string) $pNo);
                if ($pNo !== '') {
                    $supplyByKey[$key]['packings'][$pNo] = true;
                }
            }
        }

        foreach ($inTransitIssues as $issueItem) {
            $qty = max(0.0, (float) $issueItem->qty);
            if ($qty <= 1e-9) {
                continue;
            }
            $otherWo = $issueItem->materialIssue?->workOrder;
            $otherWoId = (int) ($issueItem->materialIssue?->work_order_id ?? 0);
            $key = $otherWoId.'|'.($issueItem->work_order_item_id ?? 0);
            if (! isset($supplyByKey[$key])) {
                $supplyByKey[$key] = [
                    'balance' => 0.0,
                    'status' => $otherWo?->status,
                    'wo_no' => $otherWo?->wo_no,
                    'item' => $issueItem->workOrderItem,
                    'same_machine' => false,
                    'packings' => [],
                ];
            }
            $supplyByKey[$key]['balance'] += $qty;
            foreach ((array) ($issueItem->packing_nos ?? []) as $pNo) {
                $pNo = trim((string) $pNo);
                if ($pNo !== '') {
                    $supplyByKey[$key]['packings'][$pNo] = true;
                }
            }
        }

        uasort($supplyByKey, fn (array $a, array $b): int => ($b['same_machine'] <=> $a['same_machine']));

        $rawSurplus = 0.0;
        $packingLabels = [];

        foreach ($supplyByKey as $entry) {
            $balance = (float) $entry['balance'];
            $otherStatus = $entry['status'];
            $otherItem = $entry['item'];

            if (in_array($otherStatus, ['completed', 'cancelled'], true) || $otherItem === null) {
                $groupSurplus = $balance;
            } else {
                $otherRemainingNeed = max(0.0, (float) $otherItem->qty_required - (float) $otherItem->qty_consumed);
                $groupSurplus = max(0.0, $balance - $otherRemainingNeed);
            }

            if ($groupSurplus <= 1e-9) {
                continue;
            }

            $rawSurplus += $groupSurplus;
            $woNo = (string) ($entry['wo_no'] ?? '');
            foreach (array_keys($entry['packings']) as $pNo) {
                $label = $woNo !== '' ? "{$pNo} · {$woNo}" : $pNo;
                $packingLabels[$label] = true;
            }
        }

        if ($rawSurplus <= 1e-9) {
            return ['qty' => 0.0, 'packings' => []];
        }

        // Kurangi klaim WO aktif lain yang di-release memakai sisa lantai produksi
        // (status released/in_progress tanpa bon issue & tanpa booking gudang sendiri).
        $claimed = $this->claimedFloorSurplus($woId, $allowedPartIds, $uom);
        $netSurplus = round(max(0.0, $rawSurplus - $claimed), 4);

        return [
            'qty' => $netSurplus,
            'packings' => $netSurplus > 1e-9 ? array_keys($packingLabels) : [],
        ];
    }

    /**
     * Sisa surplus material kompatibel dari WO lain di lantai produksi.
     */
    public function sharedMachineSurplus(WorkOrderItem $item, ?WorkOrder $workOrder = null): float
    {
        return $this->sharedMachineSurplusDetails($item, $workOrder)['qty'];
    }

    /**
     * Total sisa kebutuhan WO aktif lain yang telah di-release langsung memakai
     * surplus lantai produksi (tanpa bon issue/booking sendiri) agar surplus
     * tidak diklaim ganda oleh WO ke-3 dst.
     *
     * @param  list<int>  $allowedPartIds
     */
    private function claimedFloorSurplus(int $excludeWoId, array $allowedPartIds, ?string $uom): float
    {
        $otherItems = WorkOrderItem::query()
            ->where('work_order_id', '!=', $excludeWoId)
            ->whereColumn('qty_required', '>', 'qty_consumed')
            ->whereHas('workOrder', fn ($q) => $q->whereIn('status', ['released', 'in_progress']))
            ->where(function ($q) use ($allowedPartIds) {
                $q->whereIn('child_part_id', $allowedPartIds)
                    ->orWhereIn('selected_part_id', $allowedPartIds)
                    ->orWhereHas('allocations', fn ($a) => $a->whereIn('part_id', $allowedPartIds));
            })
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('material_issue_items as mii')
                    ->join('material_issues as mi', 'mi.id', '=', 'mii.material_issue_id')
                    ->whereColumn('mii.work_order_item_id', 'work_order_items.id')
                    ->where('mi.status', 'posted');
            })
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('work_order_material_bookings as wmb')
                    ->whereColumn('wmb.work_order_item_id', 'work_order_items.id')
                    ->whereIn('wmb.status', [
                        WorkOrderMaterialBooking::STATUS_BOOKED,
                        WorkOrderMaterialBooking::STATUS_CONSUMED,
                    ]);
            })
            ->get(['id', 'work_order_id', 'qty_required', 'qty_consumed', 'uom_rm', 'child_part_id']);

        $claimed = 0.0;
        foreach ($otherItems as $other) {
            if ($uom !== null && UomCatalog::normalize((string) $other->uom_rm) !== $uom) {
                continue;
            }
            $claimed += max(0.0, (float) $other->qty_required - (float) $other->qty_consumed);
        }

        return round($claimed, 4);
    }

    /**
     * Kebutuhan bersih item WO.
     */
    public function netRequirement(WorkOrderItem $item, ?WorkOrder $workOrder = null): array
    {
        $planReq = $this->planRequirement($item, $workOrder);
        $required = $planReq['effective_required'];
        $issued = round($this->issuedQty($item), 4);
        $ownAtMachine = $this->stockAtMachine($item);
        $sharedDetails = $this->sharedMachineSurplusDetails($item, $workOrder);
        $sharedAtMachine = $sharedDetails['qty'];

        return [
            'required_qty' => $required,
            'wo_required_qty' => $planReq['wo_required'],
            'planned_qty' => $planReq['planned_qty'],
            'cons_per_unit' => $planReq['cons_per_unit'],
            'plan_required_qty' => $planReq['plan_required'],
            'has_daily_plan' => $planReq['has_daily_plan'],
            'issued_qty' => $issued,
            'own_stock_at_machine' => $ownAtMachine,
            'shared_machine_stock' => $sharedAtMachine,
            'shared_machine_packings' => $sharedDetails['packings'],
            'stock_at_machine' => round($ownAtMachine + $sharedAtMachine, 4),
            'net_requirement' => BundlePacking::netRequirement($required, round($issued + $sharedAtMachine, 4)),
        ];
    }

    /**
     * Qty yang masih boleh di-issue untuk item ini.
     */
    public function remainingQty(WorkOrderItem $item, ?WorkOrder $workOrder = null): float
    {
        return $this->netRequirement($item, $workOrder)['net_requirement'];
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
        $net = $this->netRequirement($item, $workOrder);
        $receive = $stock->receive;
        $arrivalItem = $receive?->arrivalItem;

        // Satuan kemasan individual yang dicentang: pakai jenis packing dalam jika ada (mis. BOX),
        // fallback ke bundle_unit (mis. PALLET / BUNDLE)
        $itemPackingUnit = $receive?->packing_type ?: ($receive?->bundle_unit ?: 'bundle');

        // `net_requirement` sudah memperhitungkan issued + stok surplus di mesin.
        $plan = BundlePacking::plan(
            $net['required_qty'],
            round($net['issued_qty'] + $net['shared_machine_stock'], 4),
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
            'packings' => $this->packingRows($receive, $stock, $uom, $plan['net_requirement']),
            'net_requirement' => $plan['net_requirement'],
            'required_qty' => $net['required_qty'],
            'wo_required_qty' => $net['wo_required_qty'],
            'planned_qty' => $net['planned_qty'],
            'cons_per_unit' => $net['cons_per_unit'],
            'plan_required_qty' => $net['plan_required_qty'],
            'has_daily_plan' => $net['has_daily_plan'],
            'issued_qty' => $net['issued_qty'],
            'own_stock_at_machine' => $net['own_stock_at_machine'],
            'shared_machine_stock' => $net['shared_machine_stock'],
            'shared_machine_packings' => $net['shared_machine_packings'],
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

    /**
     * Ringkasan pemakaian per packing_no (uppercase) pada satu part_stock_id:
     * berapa qty yang sudah dikeluarkan dari gudang dan daftar WO pemakainya.
     *
     * Bila satu packing diambil sebagian oleh WO #1 (mis. 600 dari 1143.33 KGM),
     * sisa 543.33 KGM tetap bisa dipilih dan dikeluarkan untuk WO #2. Packing baru
     * terkunci penuh bila sisa kapasitasnya <= 0.
     *
     * @param  array<string, float>  $packingCapacities  peta packing_no (uppercase) -> qty asli
     * @return array<string, array{used_qty: float, wos: list<string>}>
     */
    public function packingUsage(int $partStockId, array $packingCapacities = []): array
    {
        $rows = DB::table('material_issue_items as mii')
            ->join('material_issues as mi', 'mi.id', '=', 'mii.material_issue_id')
            ->leftJoin('work_orders as wo', 'wo.id', '=', 'mi.work_order_id')
            ->where('mi.status', 'posted')
            ->where('mii.part_stock_id', $partStockId)
            ->whereNotNull('mii.packing_nos')
            ->orderBy('mii.id')
            ->get(['mii.packing_nos', 'mii.qty', 'wo.wo_no', 'mi.issue_no']);

        $usage = [];
        foreach ($rows as $row) {
            $decoded = is_string($row->packing_nos) ? json_decode($row->packing_nos, true) : $row->packing_nos;
            if (! is_array($decoded) || $decoded === []) {
                continue;
            }
            $keys = array_values(array_filter(
                array_map(fn ($p): string => strtoupper(trim((string) $p)), $decoded),
                fn (string $k): bool => $k !== '',
            ));
            if ($keys === []) {
                continue;
            }

            $label = (string) ($row->wo_no ?: $row->issue_no ?: '');
            $rowQty = max(0.0, (float) $row->qty);

            if (count($keys) === 1) {
                $k = $keys[0];
                $usage[$k] ??= ['used_qty' => 0.0, 'wos' => []];
                $usage[$k]['used_qty'] = round($usage[$k]['used_qty'] + $rowQty, 4);
                if ($label !== '' && ! in_array($label, $usage[$k]['wos'], true)) {
                    $usage[$k]['wos'][] = $label;
                }

                continue;
            }

            $left = $rowQty;
            foreach ($keys as $index => $k) {
                $usage[$k] ??= ['used_qty' => 0.0, 'wos' => []];
                $cap = isset($packingCapacities[$k])
                    ? max(0.0, $packingCapacities[$k] - $usage[$k]['used_qty'])
                    : $left;
                $alloc = ($index === count($keys) - 1) ? $left : min($left, $cap);
                $usage[$k]['used_qty'] = round($usage[$k]['used_qty'] + $alloc, 4);
                $left = round(max(0.0, $left - $alloc), 4);
                if ($label !== '' && ! in_array($label, $usage[$k]['wos'], true)) {
                    $usage[$k]['wos'][] = $label;
                }
            }
        }

        return $usage;
    }

    /**
     * Daftar packing riil hasil penerimaan (TAG/1, TAG/2, ...) dengan sisa qty
     * per packing, status sudah-diambil (habis vs sebagian), dan centang otomatis.
     *
     * @return list<array<string, mixed>>
     */
    private function packingRows(?IncomingReceive $receive, PartStock $stock, ?string $uom, float $netRequirement): array
    {
        if ($receive === null) {
            return [];
        }

        $packings = $receive->packings->sortBy('sequence')->values();
        if ($packings->isEmpty()) {
            return [];
        }

        $capacities = [];
        foreach ($packings as $p) {
            $capacities[strtoupper(trim((string) $p->packing_no))] = (float) $p->qty;
        }
        $usage = $this->packingUsage((int) $stock->id, $capacities);

        $remainingNeed = max(0.0, $netRequirement);
        $rows = [];

        foreach ($packings as $packing) {
            $packingNo = (string) $packing->packing_no;
            $key = strtoupper(trim($packingNo));
            $originalQty = round((float) $packing->qty, 4);
            $usedQty = round(min($originalQty, (float) ($usage[$key]['used_qty'] ?? 0.0)), 4);
            $remainingQty = round(max(0.0, $originalQty - $usedQty), 4);
            $wos = $usage[$key]['wos'] ?? [];
            $issuedToWo = $wos !== [] ? implode(', ', $wos) : null;
            $isExhausted = $remainingQty <= 1e-6;

            $selectableQty = $isExhausted ? $originalQty : $remainingQty;
            $checked = ! $isExhausted && $remainingNeed > 1e-9 && $remainingQty > 1e-9;
            if ($checked) {
                $remainingNeed = round($remainingNeed - $remainingQty, 4);
            }

            $rows[] = [
                'packing_no' => $packingNo,
                'sequence' => (int) $packing->sequence,
                'qty' => $selectableQty,
                'original_qty' => $originalQty,
                'issued_qty' => $usedQty,
                'remaining_qty' => $remainingQty,
                'uom' => UomCatalog::normalize((string) $packing->uom) ?? $uom,
                'net_weight' => $packing->net_weight !== null ? round((float) $packing->net_weight, 4) : null,
                'gross_weight' => $packing->gross_weight !== null ? round((float) $packing->gross_weight, 4) : null,
                'checked' => $checked,
                'issued' => $isExhausted,
                'issued_to_wo' => $issuedToWo,
            ];
        }

        return $rows;
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
