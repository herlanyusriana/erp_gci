<?php

namespace App\Services;

use App\Models\Bom;
use App\Models\BomItem;
use App\Models\WorkOrder;
use App\Models\WorkOrderConsumption;
use App\Models\WorkOrderItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class WoService
{
    public function __construct(protected ReceiveMaterialService $stockService) {}

    /**
     * Get the active BOM for a FG part, or null.
     */
    public function activeBomFor(int $partId): ?Bom
    {
        return Bom::query()
            ->where('part_id', $partId)
            ->where('is_active', true)
            ->with(['part', 'items.parentPart', 'items.childPart'])
            ->first();
    }

    /**
     * Build demand map key=partKey => total qty yang dibutuhkan (dikonsumsi).
     * Berlaku untuk bom_items (create) maupun work_order_items snapshot (release).
     */
    public function buildRequirements(iterable $items, float $qty, string $fgPartNumber): array
    {
        $parentsOf = [];
        foreach ($items as $it) {
            $childKey = $this->childKeyOf($it);
            $parentKey = $this->parentKeyOf($it);
            if ($childKey === '' || $parentKey === '') {
                continue;
            }
            $parentsOf[$childKey][] = [$parentKey, (float) $it->child_qty];
        }

        $memo = [];

        $req = function (string $partKey) use (&$req, &$memo, $parentsOf, $fgPartNumber, $qty) {
            if (array_key_exists($partKey, $memo)) {
                return $memo[$partKey];
            }
            if ($partKey === $fgPartNumber) {
                $memo[$partKey] = $qty;

                return $qty;
            }

            $sum = 0.0;
            foreach ($parentsOf[$partKey] ?? [] as [$parentKey, $childQty]) {
                $sum += $req($parentKey) * $childQty;
            }
            $memo[$partKey] = $sum;

            return $sum;
        };

        foreach ($items as $it) {
            $key = $this->childKeyOf($it);
            if ($key !== '') {
                $req($key);
            }
        }

        return $memo;
    }

    /**
     * Create WO (draft) + snapshot items + hitung qty_required + warning stok.
     * Return [workOrderId, warnings].
     */
    public function createWorkOrder(int $partId, float $qty, ?string $plannedDate, ?string $remarks, int $creatorId): array
    {
        $bom = $this->activeBomFor($partId);
        abort_unless($bom !== null, 422, 'Belum ada BOM aktif untuk part ini.');

        $fgKey = $bom->part?->part_number ?? (string) $bom->part_id;
        $requirements = $this->buildRequirements($bom->items, $qty, $fgKey);

        $warnings = [];

        // Parent yang di-post internal = parent of non-Subcon rows (sama dgn release).
        // Ini menentukan material mana yang "leaf" (butuh stok lama), sehingga warning
        // tidak menyinggung WIP yang diproduksi sendiri di dalam WO.
        $postedParentIds = [];
        foreach ($bom->items as $it) {
            if (strtoupper((string) $it->source) !== 'SUBCON' && $it->parent_part_id !== null) {
                $postedParentIds[$it->parent_part_id] = true;
            }
        }

        // Aggregasi kebutuhan leaf per (child_part_id, uom).
        $leafNeeds = [];
        foreach ($bom->items as $it) {
            $cid = $it->child_part_id;
            if ($cid === null || isset($postedParentIds[$cid])) {
                continue;
            }
            $parentKey = $this->parentKeyOf($it);
            $childQty = (float) $it->child_qty;
            $qtyRequired = ($parentKey !== '' && isset($requirements[$parentKey]))
                ? $requirements[$parentKey] * $childQty
                : 0.0;
            if ($qtyRequired <= 0) {
                continue;
            }
            $uom = strtoupper(trim((string) $it->uom_rm));
            $key = $cid . '|' . $uom;
            if (!isset($leafNeeds[$key])) {
                $leafNeeds[$key] = ['id' => $cid, 'uom' => trim((string) $it->uom_rm), 'name' => $it->child_part_name, 'no' => $it->childPart?->part_number ?? '', 'need' => 0.0];
            }
            $leafNeeds[$key]['need'] += $qtyRequired;
        }

        $workOrder = DB::transaction(function () use ($bom, $qty, $plannedDate, $remarks, $creatorId, $requirements, &$warnings, $leafNeeds) {
            /** @var WorkOrder $workOrder */
            $workOrder = WorkOrder::create([
                'wo_no' => WorkOrder::generateWoNo(),
                'part_id' => $bom->part_id,
                'qty' => $qty,
                'status' => 'planned',
                'planned_date' => $plannedDate,
                'remarks' => $remarks,
                'created_by' => $creatorId,
            ]);

            foreach ($bom->items as $it) {
                $parentKey = $this->parentKeyOf($it);
                $childQty = (float) $it->child_qty;
                $qtyRequired = ($parentKey !== '' && isset($requirements[$parentKey]))
                    ? $requirements[$parentKey] * $childQty
                    : 0.0;

                WorkOrderItem::create([
                    'work_order_id' => $workOrder->id,
                    'sequence' => $it->sequence,
                    'process_id' => $it->process_id,
                    'machine_id' => $it->machine_id,
                    'parent_part_id' => $it->parent_part_id,
                    'parent_part_name' => $it->parent_part_name,
                    'parent_qty' => $it->parent_qty,
                    'parent_uom' => $it->parent_uom,
                    'child_part_id' => $it->child_part_id,
                    'selected_part_id' => $it->child_part_id,
                    'child_part_name' => $it->child_part_name,
                    'size' => $it->size,
                    'child_qty' => $it->child_qty,
                    'uom_rm' => $it->uom_rm,
                    'special_code' => $it->special_code,
                    'source' => $it->source,
                    'qty_required' => $qtyRequired,
                    'qty_consumed' => 0,
                    'created_by' => $creatorId,
                ]);
            }

            // Warning stok hanya untuk material leaf (tidak diproduksi internal).
            foreach ($leafNeeds as $leaf) {
                $avail = $this->stockService->availableFifo($leaf['id'], $leaf['uom']);
                if ($avail + 1e-9 < $leaf['need']) {
                    $short = $leaf['need'] - $avail;
                    $warnings[] = sprintf(
                        '%s (%s) kurang %.4f %s (butuh %.4f, stok %.4f)',
                        $leaf['name'] ?? ('#' . $leaf['id']),
                        $leaf['no'],
                        $short,
                        strtoupper((string) $leaf['uom']),
                        $leaf['need'],
                        $avail,
                    );
                }
            }

            return $workOrder;
        });

        $warnings = array_values(array_unique($warnings));

        return [$workOrder->id, $warnings];
    }

    /**
     * Release WO. Model: BOM linear (semua parent_qty=1) → produksi dibatasi oleh
     * material LEAF paling ketat. "Leaf" = child yang TIDAK diproduksi (di-post)
     * oleh WO ini: raw material Vendor/FREE_ISSUE, RM inline (source=Prod tapi
     * child bukan WIP internal), dan WIP balikan subkon (output source=Subcon tidak
     * di-post, jadi dibutuhkan dari stok terima sebelumnya).
     *
     * 1. Rasio layak r = min(1, min(avail_leaf / need_leaf)), need di-agregasi per
     *    (child_part_id, uom) agar baris duplikat / leaf bersama tidak double-count.
     * 2. Proses item urut sequence (bottom-up): consume child FIFO r×need, lalu post
     *    output parent (non-Subcon) r×demand — SEKALI per parent (join rows dedupe).
     * 3. Stok leaf kurang → WO tetap release (r < 1), catat shortage material pengikat.
     *
     * Return [workOrder, shortages].
     */
    public function releaseWorkOrder(WorkOrder $workOrder, ?int $actorId = null): array
    {
        abort_if($workOrder->status !== 'planned', 422, 'WO hanya bisa di-release dari status planned.');

        $items = $workOrder->items()->with(['parentPart', 'childPart'])->get();
        $workOrder->load('part');
        $fgKey = $workOrder->part?->part_number ?? (string) $workOrder->part_id;
        $requirements = $this->buildRequirements($items, (float) $workOrder->qty, $fgKey);

        $selectedIds = $items->mapWithKeys(fn ($it) => [$it->id => $it->selected_part_id ?: $it->child_part_id]);

        // Parent yang output-nya TIDAK di-post menurut design: source=Subcon →
        // output balik via Receive manual.
        $postedParentIds = [];
        foreach ($items as $it) {
            if (strtoupper((string) $it->source) !== 'SUBCON' && $it->parent_part_id !== null) {
                $postedParentIds[$it->parent_part_id] = true;
            }
        }

        // Leaf constraints: child yang tidak diproduksi internal → butuh stok lama.
        // Aggregasi per (child_part_id, uom) untuk duplikat/shared leaf.
        $leafNeeds = [];
        foreach ($items as $it) {
            $cid = $selectedIds[$it->id] ?? $it->child_part_id;
            $need = (float) $it->qty_required;
            if ($cid === null || $need <= 0) {
                continue;
            }
            if (isset($postedParentIds[$cid])) {
                continue; // diproduksi dalam WO ini
            }
            $key = $cid . '|' . strtoupper(trim((string) $it->uom_rm));
            if (!isset($leafNeeds[$key])) {
                $leafNeeds[$key] = [
                    'part_id' => $cid,
                    'uom' => trim((string) $it->uom_rm),
                    'name' => $it->child_part_name,
                    'part_no' => $it->childPart?->part_number ?? '',
                    'need' => 0.0,
                ];
            }
            $leafNeeds[$key]['need'] += $need;
        }

        // Rasio layak global.
        $ratio = 1.0;
        foreach ($leafNeeds as $key => $leaf) {
            $avail = $this->stockService->availableFifo($leaf['part_id'], $leaf['uom']);
            $leafNeeds[$key]['avail'] = $avail;
            if ($leaf['need'] > 0) {
                $ratio = min($ratio, $avail / $leaf['need']);
            }
        }
        $ratio = max(0.0, min(1.0, $ratio));

        $shortages = [];

        $workOrder = DB::transaction(function () use ($workOrder, $items, $requirements, $ratio, $actorId, $selectedIds, &$shortages) {
            $releasedAt = now();
            $postedParents = []; // dedupe posting output (parent bisa multi-row join)

            foreach ($this->sorted($items) as $it) {
                $childPartId = $selectedIds[$it->id] ?? $it->child_part_id;
                $need = (float) $it->qty_required;
                $target = round($need * $ratio, 4);

                // CONSUME child sebesar r×need (per row; duplikat row = konsumsi ganda, benar).
                $taken = 0.0;
                if ($childPartId !== null && $target > 0) {
                    $alloc = $this->stockService->consumeFifoByUom($childPartId, $target, $it->uom_rm);
                    foreach ($alloc as $a) {
                        $taken += (float) $a['take_qty'];
                        WorkOrderConsumption::create([
                            'work_order_id' => $workOrder->id,
                            'work_order_item_id' => $it->id,
                            'part_stock_id' => $a['part_stock_id'],
                            'part_id' => $childPartId,
                            'qty' => (float) $a['take_qty'],
                            'uom' => $a['uom'],
                        ]);
                    }
                }
                $it->update(['qty_consumed' => $taken]);

                // POST output parent (non-Subcon) sebesar r×demand — SEKALI per parent.
                if (strtoupper((string) $it->source) !== 'SUBCON' && $it->parent_part_id !== null) {
                    $parentKey = $this->parentKeyOf($it);
                    if (!isset($postedParents[$parentKey])) {
                        $demand = $requirements[$parentKey] ?? 0.0;
                        $outQty = round($demand * $ratio, 4);
                        if ($outQty > 0) {
                            $this->postStepOutput($workOrder, $it, $outQty, $releasedAt);
                        }
                        $postedParents[$parentKey] = true;
                    }
                }
            }

            $workOrder->update([
                'status' => 'in_progress',
                'released_at' => $releasedAt,
                'updated_by' => $actorId,
            ]);

            return $workOrder->fresh(['items', 'part']);
        });

        // Shortage report: material pengikat (binding) bila r < 1.
        if ($ratio < 1 - 1e-9) {
            $eps = 1e-6;
            foreach ($leafNeeds as $leaf) {
                if ($leaf['need'] <= 0) {
                    continue;
                }
                $rowRatio = $leaf['avail'] / $leaf['need'];
                if ($rowRatio > $ratio + $eps) {
                    continue; // bukan constraint pengikat
                }
                $shortages[] = [
                    'child_part_name' => $leaf['name'],
                    'child_part_no' => $leaf['part_no'],
                    'uom' => $leaf['uom'],
                    'required' => round($leaf['need'], 4),
                    'available' => round($leaf['avail'], 4),
                    'consumed' => round($leaf['avail'], 4),
                    'short' => round($leaf['need'] - $leaf['avail'], 4),
                ];
            }
        }

        return [$workOrder, $shortages];
    }

    public function complete(WorkOrder $workOrder, ?int $actorId = null): WorkOrder
    {
        abort_unless($workOrder->status === 'in_progress', 422, 'WO belum bisa di-complete.');
        $workOrder->update([
            'status' => 'completed',
            'completed_at' => now(),
            'updated_by' => $actorId,
        ]);

        return $workOrder->fresh();
    }

    public function cancel(WorkOrder $workOrder, ?int $actorId = null): WorkOrder
    {
        abort_if($workOrder->status === 'completed', 422, 'WO completed tidak bisa dibatalkan.');
        $workOrder->update(['status' => 'cancelled', 'updated_by' => $actorId]);

        return $workOrder->fresh();
    }

    private function sorted(Collection $items): Collection
    {
        return $items->sortBy(fn ($it) => [$it->sequence ?? 0, $it->id]);
    }

    private function postStepOutput(WorkOrder $workOrder, WorkOrderItem $row, float $qty, $receivedAt): void
    {
        $partId = $row->parent_part_id;
        $uom = strtoupper(trim((string) $row->parent_uom)) ?: 'PCS';
        $tag = $workOrder->wo_no . '#' . ($row->parentPart?->part_number ?? $row->parent_part_name ?? $partId);

        $this->stockService->postProductionStock($partId, $tag, $qty, $uom, $receivedAt);
    }

    private function childKeyOf($it): string
    {
        if ($it->child_part_id !== null) {
            $num = $it->childPart?->part_number;
            if ($num !== null && $num !== '') {
                return (string) $num;
            }

            return (string) $it->child_part_id;
        }

        return (string) ($it->child_part_name ?? '');
    }

    private function parentKeyOf($it): string
    {
        if ($it->parent_part_id !== null) {
            $num = $it->parentPart?->part_number;
            if ($num !== null && $num !== '') {
                return (string) $num;
            }

            return (string) $it->parent_part_id;
        }

        return (string) ($it->parent_part_name ?? '');
    }
}