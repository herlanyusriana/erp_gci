<?php

namespace App\Services;

use App\Models\ProductionResult;
use App\Models\WorkOrder;
use App\Models\WorkOrderConsumption;
use App\Support\UomCatalog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Hasil produksi per step (parent_part) untuk model WIP per proses.
 *
 * - Child WIP (diproduksi internal di WO ini) → dikonsumsi FIFO (backflush).
 * - Child RM → sudah dikonsumsi saat issue out, di-skip di sini.
 * - Output parent sebesar qty_good diposting ke stok (WIP atau FG).
 */
class ProductionResultService
{
    public function __construct(protected ReceiveMaterialService $stockService) {}

    /**
     * @param  array{qty_good:float|int|string, qty_reject?:float|int|string|null, result_date?:string|null, shift?:string|null, machine_id?:int|null, notes?:string|null}  $data
     */
    public function report(WorkOrder $workOrder, int $parentPartId, array $data, ?int $actorId = null): ProductionResult
    {
        abort_if($workOrder->status !== 'in_progress', 422, __('WO belum di-release.'));

        $rows = $workOrder->items()
            ->with(['parentPart:id,part_number', 'childPart:id,part_number'])
            ->where('parent_part_id', $parentPartId)
            ->get();

        if ($rows->isEmpty()) {
            throw ValidationException::withMessages(['parent_part_id' => __('Step ini tidak ada di WO.')]);
        }

        $qtyGood = (float) ($data['qty_good'] ?? 0);
        $qtyReject = (float) ($data['qty_reject'] ?? 0);

        if ($qtyGood <= 0) {
            throw ValidationException::withMessages(['qty_good' => __('Qty good harus lebih dari 0.')]);
        }
        if ($qtyReject < 0) {
            throw ValidationException::withMessages(['qty_reject' => __('Qty reject tidak boleh negatif.')]);
        }

        $produced = (float) ProductionResult::query()
            ->where('work_order_id', $workOrder->id)
            ->where('parent_part_id', $parentPartId)
            ->sum('qty_good');

        if ($produced + $qtyGood > (float) $workOrder->qty + 1e-9) {
            throw ValidationException::withMessages([
                'qty_good' => __('Total hasil step ini melebihi qty WO (:qty).', ['qty' => $workOrder->qty]),
            ]);
        }

        // Parent yang diproduksi internal di WO ini = WIP.
        $internalParents = $workOrder->items()->pluck('parent_part_id')
            ->filter()->unique()->values()->all();

        // Step sebelumnya harus selesai / material sudah di-book: pastikan cukup.
        foreach ($rows as $row) {
            $childId = $row->child_part_id;
            if ($childId === null) {
                continue;
            }
            $need = (float) $row->child_qty * $qtyGood;
            if ($need <= 0) {
                continue;
            }

            // WIP: stok dari step sebelumnya. RM: booking item ini + stok bebas.
            $available = $this->stockService->availableFifo((int) $childId, $row->uom_rm);
            if (! in_array($childId, $internalParents, true)) {
                $available += $this->stockService->bookedQtyForItem($row->id, (int) $childId, $row->uom_rm);
            }

            if ($available + 1e-9 < $need) {
                throw ValidationException::withMessages([
                    'qty_good' => __('Stok WIP :part kurang (:avail tersedia, :need dibutuhkan). Selesaikan step sebelumnya.', [
                        'part' => $row->childPart?->part_number ?? $childId,
                        'avail' => rtrim(rtrim(number_format($available, 4, '.', ''), '0'), '.'),
                        'need' => rtrim(rtrim(number_format($need, 4, '.', ''), '0'), '.'),
                    ]),
                ]);
            }
        }

        return DB::transaction(function () use ($workOrder, $rows, $parentPartId, $qtyGood, $qtyReject, $data, $actorId) {
            $reportedAt = isset($data['result_date']) && $data['result_date']
                ? Carbon::parse($data['result_date'])
                : now();

            // Backflush: booking item ini dulu (RM), lalu stok FIFO (WIP / sisa).
            // Stok fisik baru berkurang di sini — bukan saat release.
            foreach ($rows as $row) {
                $childId = $row->child_part_id;
                if ($childId === null) {
                    continue;
                }
                $need = (float) $row->child_qty * $qtyGood;
                if ($need <= 0) {
                    continue;
                }

                $taken = 0.0;
                foreach ($this->stockService->consumeForItem($row->id, (int) $childId, $need, $row->uom_rm, $actorId) as $alloc) {
                    $taken += (float) $alloc['take_qty'];
                    WorkOrderConsumption::create([
                        'work_order_id' => $workOrder->id,
                        'work_order_item_id' => $row->id,
                        'part_stock_id' => $alloc['part_stock_id'],
                        'part_id' => $childId,
                        'qty' => (float) $alloc['take_qty'],
                        'uom' => $alloc['uom'],
                    ]);
                }
                $row->increment('qty_consumed', $taken);
            }

            // Posting output parent (WIP atau FG).
            $first = $rows->first();
            $uom = UomCatalog::normalize((string) $first->parent_uom) ?? UomCatalog::PIECE;
            $tag = $workOrder->wo_no.'#'.($first->parentPart?->part_number ?? $parentPartId);
            $this->stockService->postProductionStock($parentPartId, $tag, $qtyGood, $uom, $reportedAt);

            return ProductionResult::create([
                'work_order_id' => $workOrder->id,
                'parent_part_id' => $parentPartId,
                'process_id' => $first->process_id,
                'machine_id' => $data['machine_id'] ?? $first->machine_id,
                'result_date' => $reportedAt->toDateString(),
                'shift' => $data['shift'] ?? null,
                'qty_good' => $qtyGood,
                'qty_reject' => $qtyReject,
                'uom' => $uom,
                'reported_by' => $actorId,
                'notes' => $data['notes'] ?? null,
                'created_by' => $actorId,
                'updated_by' => $actorId,
            ]);
        });
    }

    /**
     * Progres hasil per parent_part untuk sebuah WO.
     *
     * @return array<int, array{parent_part_id:int, part_number:string|null, produced:float, reject:float}>
     */
    public function progress(WorkOrder $workOrder): array
    {
        return ProductionResult::query()
            ->where('work_order_id', $workOrder->id)
            ->with('parentPart:id,part_number')
            ->get()
            ->groupBy('parent_part_id')
            ->map(fn ($group) => [
                'parent_part_id' => (int) $group->first()->parent_part_id,
                'part_number' => $group->first()->parentPart?->part_number,
                'produced' => (float) $group->sum('qty_good'),
                'reject' => (float) $group->sum('qty_reject'),
            ])
            ->values()
            ->all();
    }

    /** Total output FG yang sudah dilaporkan untuk sebuah WO. */
    public function fgProduced(WorkOrder $workOrder): float
    {
        return (float) ProductionResult::query()
            ->where('work_order_id', $workOrder->id)
            ->where('parent_part_id', $workOrder->part_id)
            ->sum('qty_good');
    }
}
