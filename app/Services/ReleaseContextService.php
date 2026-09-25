<?php

namespace App\Services;

use App\Models\Part;
use App\Models\PartSubstitute;
use App\Models\WorkOrder;
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
                'childPart:id,part_number,part_name',
                'allocations.part:id,part_number,part_name',
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

        $parts = Part::query()->whereIn('id', $allPartIds)->get(['id', 'part_number', 'part_name', 'size'])->keyBy('id');

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
            }

            $materials = collect($candidates)->map(function (array $candidate) use ($stock, $uom, $parts, $it, $issuedForItem) {
                $partId = $candidate['part_id'];
                $stats = $stock[$partId] ?? ['stock_qty' => 0.0, 'available_qty' => 0.0, 'tags' => []];
                $partSize = $parts[$partId]->size ?? null;

                return [
                    'part_id' => $partId,
                    'part_number' => $parts[$partId]->part_number ?? null,
                    'part_name' => $parts[$partId]->part_name ?? null,
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

            $rows[] = [
                'work_order_item_id' => $it->id,
                'sequence' => $it->sequence,
                'process' => $it->process?->process_name,
                'machine' => $it->machine?->machine_name,
                'part' => $it->childPart ? [
                    'id' => $it->childPart->id,
                    'part_number' => $it->childPart->part_number,
                    'part_name' => $it->childPart->part_name,
                ] : null,
                'child_part_name' => $it->child_part_name,
                'uom' => $uom,
                'required' => round((float) $it->qty_required, 4),
                'consumed' => round((float) $it->qty_consumed, 4),
                'remaining' => round(max(0.0, (float) $it->qty_required - (float) $it->qty_consumed), 4),
                'stock_state' => $materials->contains(fn (array $m) => $m['available_qty'] > 1e-9) ? 'available' : 'none',
                'size' => $firstMaterial !== null ? $firstMaterial['size'] : $it->size,
                'issued_qty' => round((float) $issuedForItem->sum('issued_qty'), 4),
                'materials' => $materials->all(),
                'allowed_parts' => $allowed,
                'recommended_tags' => $recommended,
            ];
        }

        return $rows;
    }
}
