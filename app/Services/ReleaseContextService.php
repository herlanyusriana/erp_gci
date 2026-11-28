<?php

namespace App\Services;

use App\Models\Part;
use App\Models\PartSubstitute;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use App\Support\UomCatalog;
use Illuminate\Support\Facades\DB;

/**
 * Menyusun konteks material untuk layar issue APK: kebutuhan per item, material
 * yang benar-benar akan discan, dan tag yang layak dipakai.
 *
 * Material yang ditampilkan adalah part hasil alokasi planner; bila belum ada
 * alokasi, substitute aktif yang berstok. Main part BOM adalah acuan yang tidak
 * memegang stok, sehingga tidak pernah ditampilkan sebagai material yang discan.
 */
class ReleaseContextService
{
    public function __construct(
        private WoService $woService,
        private PartStockTagService $stockTags,
        private MaterialIssueBundleService $bundleService,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function items(WorkOrder $workOrder): array
    {
        $items = $workOrder->items()
            ->with([
                'process:id,process_name',
                'machine:id,machine_code,machine_name',
                'childPart:id,part_number,part_name,model,part_type_id',
                'childPart.partType:id,code',
                'allocations.part:id,part_number,part_name,model',
            ])
            ->get();

        $postedParentIds = [];
        foreach ($items as $it) {
            if (strtoupper((string) $it->source) !== 'SUBCON' && $it->parent_part_id !== null) {
                $postedParentIds[$it->parent_part_id] = true;
            }
        }

        // Hanya leaf (child bukan WIP internal) yang perlu scan.
        $leafItems = $items->reject(
            fn ($it) => $it->child_part_id !== null && isset($postedParentIds[$it->child_part_id]),
        )->values();

        $allowedByItem = [];
        $allPartIds = [];
        foreach ($leafItems as $it) {
            $ids = $this->woService->allowedPartIdsForItem($it);
            $allowedByItem[$it->id] = $ids;
            $allPartIds = array_merge($allPartIds, $ids);
        }
        $allPartIds = array_values(array_unique($allPartIds));

        $parts = Part::query()->whereIn('id', $allPartIds)->get(['id', 'part_number', 'part_name', 'model', 'size'])->keyBy('id');

        // Stok dan tag dikelompokkan per satuan: agregat KGM tidak boleh dicampur PCS.
        $stockByUom = [];
        foreach ($leafItems->groupBy(fn ($it) => UomCatalog::normalize((string) $it->uom_rm) ?? '') as $uomKey => $groupItems) {
            $ids = $groupItems
                ->flatMap(fn ($it) => $allowedByItem[$it->id])
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values()
                ->all();
            $stockByUom[$uomKey] = $this->stockTags->forParts($ids, $uomKey === '' ? null : $uomKey);
        }

        // Substitute aktif per main material (satu query untuk semua item).
        $mainPartIds = $leafItems->pluck('child_part_id')->filter()->unique()->values()->all();
        $substitutesByMain = $mainPartIds === []
            ? collect()
            : PartSubstitute::query()
                ->whereIn('part_id', $mainPartIds)
                ->where('is_active', true)
                ->get(['part_id', 'substitute_part_id'])
                ->groupBy('part_id');

        // Material yang sudah keluar per item + per part (satu query untuk semua item).
        $issuedByItemPart = DB::table('material_issue_items as mii')
            ->join('material_issues as mi', 'mi.id', '=', 'mii.material_issue_id')
            ->where('mi.status', 'posted')
            ->whereIn('mii.work_order_item_id', $leafItems->pluck('id')->all())
            ->groupBy('mii.work_order_item_id', 'mii.part_id')
            ->selectRaw('mii.work_order_item_id, mii.part_id, SUM(mii.qty) AS issued_qty')
            ->get()
            ->groupBy('work_order_item_id');

        $rows = [];
        foreach ($leafItems as $it) {
            $allowedIds = $allowedByItem[$it->id];
            $mainId = (int) $it->child_part_id;
            $uom = UomCatalog::normalize((string) $it->uom_rm);
            $stock = $stockByUom[$uom ?? ''] ?? [];

            $allowed = collect($allowedIds)->map(fn ($id) => [
                'id' => (int) $id,
                'part_number' => $parts[$id]->part_number ?? null,
                'part_name' => $parts[$id]->part_name ?? null,
                'model' => $parts[$id]->model ?? null,
                'kind' => (int) $id === $mainId ? 'main' : 'substitute',
            ])->values();

            $recommended = collect($allowedIds)
                ->flatMap(fn ($id) => $stock[(int) $id]['tags'] ?? [])
                ->values();

            $issuedForItem = $issuedByItemPart->get($it->id, collect())->keyBy(fn ($row) => (int) $row->part_id);

            // Material yang ditampilkan: alokasi planner; bila belum ada, substitute
            // aktif yang berstok. Main part BOM tidak pernah ditampilkan.
            $allocations = $it->allocations->sortByDesc('qty')->values();

            if ($allocations->isNotEmpty()) {
                $candidates = $allocations->map(fn ($a) => [
                    'part_id' => (int) $a->part_id,
                    'source' => 'allocation',
                    'allocation_qty' => round((float) $a->qty, 4),
                ])->all();
            } else {
                $substituteIds = $substitutesByMain->get($it->child_part_id, collect())
                    ->pluck('substitute_part_id')
                    ->map(fn ($id) => (int) $id)
                    ->values();
                $withStock = $substituteIds->filter(fn ($id) => ($stock[$id]['available_qty'] ?? 0) > 1e-9);

                $candidates = ($withStock->isNotEmpty() ? $withStock : $substituteIds)
                    ->map(fn ($id) => ['part_id' => $id, 'source' => 'substitute', 'allocation_qty' => null])
                    ->values()
                    ->all();

                // Untuk item berbahan WIP dari WO lain (mis. blank hasil Press L+R),
                // stok berada pada child_part_id itu sendiri.
                if ($candidates === [] && strtoupper((string) ($it->childPart?->partType?->code ?? '')) === 'WIP' && $it->child_part_id !== null) {
                    $candidates = [['part_id' => (int) $it->child_part_id, 'source' => 'wip', 'allocation_qty' => null]];
                }
            }

            $materials = collect($candidates)->map(function (array $candidate) use ($stock, $uom, $parts, $it, $issuedForItem) {
                $partId = $candidate['part_id'];
                $stats = $stock[$partId] ?? ['stock_qty' => 0.0, 'available_qty' => 0.0, 'tags' => []];
                $partSize = $parts[$partId]->size ?? null;

                return [
                    'part_id' => $partId,
                    'part_number' => $parts[$partId]->part_number ?? null,
                    'part_name' => $parts[$partId]->part_name ?? null,
                    'model' => $parts[$partId]->model ?? null,
                    'size' => ($partSize !== null && $partSize !== '') ? $partSize : $it->size,
                    'uom' => $uom,
                    'source' => $candidate['source'],
                    'allocation_qty' => $candidate['allocation_qty'],
                    'issued_qty' => round((float) ($issuedForItem->get($partId)?->issued_qty ?? 0), 4),
                    'stock_qty' => $stats['stock_qty'],
                    'available_qty' => $stats['available_qty'],
                    'tags' => $stats['tags'],
                ];
            })->values();

            $firstMaterial = $materials->first();
            $issuedTotal = round((float) $issuedForItem->sum('issued_qty'), 4);
            $net = $this->bundleService->netRequirement($it, $workOrder);

            $rows[] = [
                'work_order_item_id' => $it->id,
                'sequence' => $it->sequence,
                'process' => $it->process?->process_name,
                'machine' => $it->machine?->machine_name,
                'part' => $it->childPart ? [
                    'id' => $it->childPart->id,
                    'part_number' => $it->childPart->part_number,
                    'part_name' => $it->childPart->part_name,
                    'model' => $it->childPart->model,
                ] : null,
                'child_part_name' => $it->child_part_name,
                'uom' => $uom,
                'required' => $net['required_qty'],
                'wo_required' => $net['wo_required_qty'],
                'planned_qty' => $net['planned_qty'],
                'cons_per_unit' => $net['cons_per_unit'],
                'shared_machine_stock' => $net['shared_machine_stock'],
                'shared_machine_packings' => $net['shared_machine_packings'],
                'consumed' => round((float) $it->qty_consumed, 4),
                'remaining' => $net['net_requirement'],
                'stock_state' => $materials->contains(fn (array $m) => $m['available_qty'] > 1e-9) ? 'available' : 'none',
                'is_auxiliary' => self::isAuxiliaryItem($it),
                'size' => $firstMaterial !== null ? $firstMaterial['size'] : $it->size,
                'issued_qty' => $issuedTotal,
                'materials' => $materials->all(),
                'allowed_parts' => $allowed,
                'recommended_tags' => $recommended,
            ];
        }

        return $rows;
    }

    /**
     * Ringkasan kecukupan stok surplus di mesin (dari WO lain bermaterial sama di
     * mesin yang sama). Bila seluruh kebutuhan utama sudah tertutup oleh surplus
     * mesin, WO planned boleh langsung di-release tanpa scan tag baru di gudang.
     *
     * @param  list<array<string, mixed>>  $items
     * @return array{machine_surplus_qty: float, can_release_with_machine_stock: bool}
     */
    public function machineStockSummary(WorkOrder $workOrder, array $items): array
    {
        $nonAux = collect($items)->reject(fn (array $row): bool => (bool) ($row['is_auxiliary'] ?? false));
        $totalSurplus = round((float) $nonAux->sum(fn (array $row): float => (float) ($row['shared_machine_stock'] ?? 0)), 4);
        $packings = $nonAux
            ->flatMap(fn (array $row): array => (array) ($row['shared_machine_packings'] ?? []))
            ->filter(fn ($p): bool => is_string($p) && $p !== '')
            ->unique()
            ->values()
            ->all();
        $allCovered = $nonAux->isNotEmpty()
            && $totalSurplus > 1e-9
            && $nonAux->every(fn (array $row): bool => (float) ($row['remaining'] ?? 0) <= 1e-9);

        return [
            'machine_surplus_qty' => $totalSurplus,
            'machine_surplus_packings' => $packings,
            'can_release_with_machine_stock' => $workOrder->status === 'planned' && $allCovered,
        ];
    }

    /**
     * Apakah item WO merupakan material penunjang (mis. OPP tape) yang issue-nya
     * boleh menyusul setelah main part jalan.
     */
    public static function isAuxiliaryItem(WorkOrderItem $item): bool
    {
        $partNo = strtoupper(trim((string) ($item->childPart?->part_number ?? '')));
        $partName = strtoupper(trim((string) ($item->child_part_name ?? $item->childPart?->part_name ?? '')));

        return str_starts_with($partNo, 'OPT')
            || str_starts_with($partNo, 'OPO')
            || str_contains($partName, 'OPP ')
            || $partName === 'OPP';
    }

    /**
     * Ringkasan ketersediaan stok WIP internal pada WO ini.
     * Bila masih ada WIP di mesin/stok, WO planned boleh langsung di-release.
     *
     * @return array{wip_available_qty: float, can_release_with_wip: bool, wip_stocks: list<array<string, mixed>>}
     */
    public function wipSummary(WorkOrder $workOrder): array
    {
        $items = $workOrder->items()
            ->with(['parentPart:id,part_number,part_name,model'])
            ->get();

        $wipItems = $items
            ->filter(fn ($it) => strtoupper((string) $it->source) !== 'SUBCON'
                && $it->parent_part_id !== null
                && (int) $it->parent_part_id !== (int) $workOrder->part_id)
            ->unique('parent_part_id')
            ->values();

        if ($wipItems->isEmpty()) {
            return [
                'wip_available_qty' => 0.0,
                'can_release_with_wip' => false,
                'wip_stocks' => [],
            ];
        }

        $wipPartIds = $wipItems->pluck('parent_part_id')->map(fn ($id) => (int) $id)->all();
        $stockByPart = $this->stockTags->forParts($wipPartIds, null);

        $wipStocks = [];
        $totalAvailable = 0.0;

        foreach ($wipItems as $it) {
            $pid = (int) $it->parent_part_id;
            $avail = (float) ($stockByPart[$pid]['available_qty'] ?? 0.0);
            if ($avail <= 1e-9) {
                continue;
            }
            $totalAvailable += $avail;
            $wipStocks[] = [
                'part_id' => $pid,
                'part_number' => $it->parentPart?->part_number,
                'part_name' => $it->parentPart?->part_name ?? $it->parent_part_name,
                'model' => $it->parentPart?->model,
                'available_qty' => round($avail, 4),
                'uom' => UomCatalog::normalize((string) $it->parent_uom) ?? UomCatalog::PIECE,
            ];
        }

        return [
            'wip_available_qty' => round($totalAvailable, 4),
            'can_release_with_wip' => $workOrder->status === 'planned' && $totalAvailable > 1e-9,
            'wip_stocks' => $wipStocks,
        ];
    }
}
