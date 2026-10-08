<?php

namespace App\Services;

use App\Models\Machine;
use App\Models\PartStock;
use App\Models\ProductionMaterialReceipt;
use App\Models\ProductionResult;
use App\Models\WorkOrder;
use App\Models\WorkOrderConsumption;
use App\Models\WorkOrderItem;
use App\Models\WorkOrderMaterialBooking;
use App\Support\MachineRouting;
use App\Support\UomCatalog;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductionResultService
{
    public function __construct(protected ReceiveMaterialService $stockService) {}

    public function report(WorkOrder $workOrder, int $parentPartId, array $data, ?int $actorId = null): ProductionResult
    {
        return DB::transaction(function () use ($workOrder, $parentPartId, $data, $actorId) {
            $workOrder = WorkOrder::query()->lockForUpdate()->findOrFail($workOrder->id);
            abort_if($workOrder->status !== 'in_progress', 422, __('WO belum di-release.'));
            $rows = $workOrder->items()->with(['parentPart:id,part_number', 'childPart:id,part_number'])
                ->where('parent_part_id', $parentPartId)->lockForUpdate()->get();
            if ($rows->isEmpty()) {
                throw ValidationException::withMessages(['parent_part_id' => __('Step ini tidak ada di WO.')]);
            }
            $qtyGood = (float) ($data['qty_good'] ?? 0);
            $qtyReject = (float) ($data['qty_reject'] ?? 0);
            if (! is_finite($qtyGood) || $qtyGood <= 0) {
                throw ValidationException::withMessages(['qty_good' => __('Qty good harus lebih dari 0.')]);
            }
            if (! is_finite($qtyReject) || $qtyReject < 0) {
                throw ValidationException::withMessages(['qty_reject' => __('Qty reject tidak boleh negatif.')]);
            }
            try {
                $goodDecimal = BigDecimal::of($data['qty_good'])->toScale(4);
                $rejectDecimal = BigDecimal::of($data['qty_reject'] ?? 0)->toScale(4);
            } catch (MathException) {
                throw ValidationException::withMessages(['qty_good' => __('Qty hasil maksimal empat angka desimal.')]);
            }
            $produced = BigDecimal::of(ProductionResult::query()->where('work_order_id', $workOrder->id)
                ->where('parent_part_id', $parentPartId)->sum('qty_good'));
            if ($produced->plus($goodDecimal)->isGreaterThan(BigDecimal::of($workOrder->getRawOriginal('qty')))) {
                throw ValidationException::withMessages([
                    'qty_good' => __('Total hasil step ini melebihi qty WO (:qty).', ['qty' => $workOrder->qty]),
                ]);
            }
            $first = $rows->first();
            $machineId = $data['machine_id'] ?? $first->machine_id;
            $machine = $machineId !== null ? Machine::query()->lockForUpdate()->find($machineId) : null;
            foreach ($rows as $row) {
                if ($machineId !== null || $row->machine_id !== null) {
                    MachineRouting::assertEligible($machine, $row);
                }
                if (! is_finite((float) $row->qty_consumed) || $row->qty_consumed < 0) {
                    throw ValidationException::withMessages(['qty_good' => __('Saldo konsumsi tidak valid. Rekonsiliasi diperlukan.')]);
                }
            }
            $internalParents = $workOrder->items()->pluck('parent_part_id')->filter()->unique()->all();
            $reportedAt = ! empty($data['result_date']) ? Carbon::parse($data['result_date']) : now();
            $uom = $parentPartId === (int) $workOrder->part_id
                ? UomCatalog::PIECE
                : (UomCatalog::normalize((string) $first->parent_uom) ?? UomCatalog::PIECE);
            $result = ProductionResult::create([
                'work_order_id' => $workOrder->id, 'parent_part_id' => $parentPartId,
                'process_id' => $first->process_id, 'machine_id' => $machineId,
                'result_date' => $reportedAt->toDateString(), 'shift' => $data['shift'] ?? null,
                'qty_good' => (string) $goodDecimal, 'qty_reject' => (string) $rejectDecimal, 'uom' => $uom,
                'reported_by' => $actorId, 'notes' => $data['notes'] ?? null,
                'created_by' => $actorId, 'updated_by' => $actorId,
            ]);

            foreach ($rows as $row) {
                $childId = $row->child_part_id;
                $need = BigDecimal::of($row->getRawOriginal('child_qty') ?? 0)
                    ->multipliedBy($goodDecimal->plus($rejectDecimal))->toScale(10);
                if ($childId === null || $need->isLessThanOrEqualTo(0)) {
                    continue;
                }
                if (in_array($childId, $internalParents, true)) {
                    $taken = BigDecimal::zero();
                    foreach ($this->stockService->consumeFifoByUom((int) $childId, (string) $need, $row->uom_rm, $workOrder->id) as $allocation) {
                        $taken = $taken->plus($allocation['take_qty']);
                        WorkOrderConsumption::create([
                            'production_result_id' => $result->id,
                            'work_order_id' => $workOrder->id, 'work_order_item_id' => $row->id,
                            'part_stock_id' => $allocation['part_stock_id'], 'part_id' => $childId,
                            'qty' => $allocation['take_qty'], 'uom' => $allocation['uom'],
                        ]);
                    }
                    if ($taken->isLessThan($need)) {
                        throw ValidationException::withMessages(['qty_good' => __('Stok WIP tidak mencukupi. Selesaikan step sebelumnya.')]);
                    }
                } else {
                    $receipts = ProductionMaterialReceipt::query()
                        ->where('work_order_id', $workOrder->id)->where('work_order_item_id', $row->id)
                        ->where('machine_id', $machineId)->where('transfer_status', 'transferred')
                        ->where('uom', UomCatalog::normalize((string) $row->uom_rm))
                        ->orderBy('received_at')->orderBy('id')->lockForUpdate()->get();
                    $available = BigDecimal::zero();
                    foreach ($receipts as $receipt) {
                        $receipt->assertValidBalance();
                        $available = $available->plus(BigDecimal::of($receipt->getRawOriginal('qty'))->minus($receipt->getRawOriginal('qty_consumed')));
                    }
                    if ($machineId === null || $available->isLessThan($need)) {
                        throw ValidationException::withMessages(['qty_good' => __('Saldo material di mesin tidak mencukupi. Terima material untuk item WO ini terlebih dahulu.')]);
                    }
                    $remaining = $need;
                    foreach ($receipts as $receipt) {
                        if ($remaining->isZero()) {
                            break;
                        }
                        $balance = BigDecimal::of($receipt->getRawOriginal('qty'))->minus($receipt->getRawOriginal('qty_consumed'));
                        $take = $remaining->isLessThan($balance) ? $remaining : $balance;
                        if ($take->isZero()) {
                            continue;
                        }
                        $receipt->increment('qty_consumed', (string) $take);
                        WorkOrderConsumption::create([
                            'production_result_id' => $result->id, 'production_material_receipt_id' => $receipt->id,
                            'work_order_id' => $workOrder->id, 'work_order_item_id' => $row->id,
                            'part_stock_id' => $receipt->part_stock_id, 'part_id' => $receipt->part_id,
                            'qty' => (string) $take, 'uom' => $receipt->uom,
                        ]);
                        $remaining = $remaining->minus($take);
                    }
                }
                $row->increment('qty_consumed', (string) $need);
            }

            $tag = $workOrder->wo_no.'#'.($first->parentPart?->part_number ?? $parentPartId).'#R'.$result->id;
            $output = $this->stockService->postProductionStock($parentPartId, $tag, (string) $goodDecimal, $uom, $reportedAt);
            $result->update(['output_part_stock_id' => $output->id]);

            return $result;
        });
    }

    public function reverse(WorkOrder $workOrder, ProductionResult $result, ?int $actorId = null): void
    {
        DB::transaction(function () use ($workOrder, $result, $actorId) {
            $workOrder = WorkOrder::query()->lockForUpdate()->findOrFail($workOrder->id);
            $result = ProductionResult::withTrashed()->lockForUpdate()->findOrFail($result->id);
            if ($result->work_order_id !== $workOrder->id) {
                throw ValidationException::withMessages(['result' => __('Hasil tidak termasuk WO ini.')]);
            }
            if ($result->trashed()) {
                return;
            }
            if ($result->output_part_stock_id === null) {
                throw ValidationException::withMessages(['result' => __('Hasil produksi lama perlu rekonsiliasi sebelum dibatalkan.')]);
            }
            $output = PartStock::query()->lockForUpdate()->find($result->output_part_stock_id);
            if ($output === null || BigDecimal::of($output->getRawOriginal('qty'))->isLessThan($result->getRawOriginal('qty_good'))
                || WorkOrderConsumption::where('part_stock_id', $output->id)->exists()
                || WorkOrderMaterialBooking::where('part_stock_id', $output->id)->where('status', 'booked')->exists()) {
                throw ValidationException::withMessages(['result' => __('Output hasil ini sudah digunakan atau dibooking. Batalkan proses berikutnya terlebih dahulu.')]);
            }
            $outputRemaining = BigDecimal::of($output->getRawOriginal('qty'))->minus($result->getRawOriginal('qty_good'));
            PartStock::query()->whereKey($output->id)->update(['qty' => (string) $outputRemaining]);
            if ($outputRemaining->isZero()) {
                $output->delete();
            }
            $consumptions = WorkOrderConsumption::where('production_result_id', $result->id)->orderBy('id')->lockForUpdate()->get();
            foreach ($consumptions as $consumption) {
                $item = WorkOrderItem::query()->lockForUpdate()->findOrFail($consumption->work_order_item_id);
                $itemConsumed = BigDecimal::of($item->getRawOriginal('qty_consumed'));
                $consumeQty = BigDecimal::of($consumption->getRawOriginal('qty'));
                if ($consumeQty->isLessThanOrEqualTo(0) || $itemConsumed->isLessThan($consumeQty)) {
                    throw ValidationException::withMessages(['result' => __('Saldo konsumsi tidak valid. Rekonsiliasi diperlukan.')]);
                }
                if ($consumption->production_material_receipt_id !== null) {
                    $receipt = ProductionMaterialReceipt::query()->lockForUpdate()->findOrFail($consumption->production_material_receipt_id);
                    $receipt->assertValidBalance();
                    $receiptConsumed = BigDecimal::of($receipt->getRawOriginal('qty_consumed'));
                    if ($receiptConsumed->isLessThan($consumeQty)) {
                        throw ValidationException::withMessages(['result' => __('Saldo konsumsi tidak valid. Rekonsiliasi diperlukan.')]);
                    }
                    ProductionMaterialReceipt::query()->whereKey($receipt->id)->update(['qty_consumed' => (string) $receiptConsumed->minus($consumeQty)]);
                } else {
                    $stock = PartStock::withTrashed()->lockForUpdate()->find($consumption->part_stock_id);
                    if ($stock === null) {
                        throw ValidationException::withMessages(['result' => __('Saldo konsumsi tidak valid. Rekonsiliasi diperlukan.')]);
                    }
                    PartStock::withTrashed()->whereKey($stock->id)->update(['qty' => (string) BigDecimal::of($stock->trashed() ? 0 : $stock->getRawOriginal('qty'))->plus($consumeQty)]);
                    if ($stock->trashed()) {
                        $stock->restore();
                    }
                }
                WorkOrderItem::query()->whereKey($item->id)->update(['qty_consumed' => (string) $itemConsumed->minus($consumeQty)]);
                $consumption->update(['reversed_by' => $actorId]);
                $consumption->delete();
            }
            $result->update(['reversed_by' => $actorId]);
            $result->delete();
        });
    }

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

    public function fgProduced(WorkOrder $workOrder): float
    {
        return (float) ProductionResult::query()
            ->where('work_order_id', $workOrder->id)
            ->where('parent_part_id', $workOrder->part_id)
            ->sum('qty_good');
    }
}
