<?php

namespace App\Services;

use App\Models\Machine;
use App\Models\MaterialIssue;
use App\Models\MaterialIssueItem;
use App\Models\PartStock;
use App\Models\ProductionMaterialReceipt;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use App\Models\WorkOrderMaterialBooking;
use App\Support\MachineRouting;
use App\Support\UomCatalog;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Penerimaan material oleh production di lantai.
 *
 * Setelah warehouse issue-out (scan tag → release WO), operator produksi
 * scan tag material yang sama + QR mesin tujuan. Saat konfirmasi, **stok
 * gudang benar-benar berkurang** — booking dilepas, `part_stocks.qty` turun.
 */
class ProductionReceiptService
{
    public function resolve(string $tag, ?int $partId = null): array
    {
        return $this->issuedItemsForTag($tag)
            ->when($partId !== null, fn (Builder $query) => $query->where('part_id', $partId))
            ->whereNotIn('id', ProductionMaterialReceipt::query()->select('material_issue_item_id'))
            ->with(['materialIssue.workOrder', 'part'])
            ->orderBy('id')->get()->map(fn (MaterialIssueItem $item) => [
                'material_issue_item_id' => $item->id,
                'material_issue_id' => $item->material_issue_id,
                'issue_no' => $item->materialIssue->issue_no,
                'work_order_id' => $item->materialIssue->work_order_id,
                'wo_no' => $item->materialIssue->workOrder->wo_no,
                'work_order_item_id' => $item->work_order_item_id,
                'tag' => $item->tag,
                'part_id' => $item->part_id,
                'part' => $item->part?->only(['part_number', 'part_name', 'model', 'size']),
                'qty' => $item->qty,
                'uom' => $item->uom,
                'invoice' => $item->invoice,
                'supplier' => $item->supplier,
                'status' => 'pending',
            ])->all();
    }

    private function issuedItemsForTag(string $tag): Builder
    {
        return MaterialIssueItem::query()
            ->whereRaw('LOWER(tag) = ?', [mb_strtolower(trim($tag))])
            ->whereHas('materialIssue', fn (Builder $query) => $query->where('status', 'posted')
                ->whereHas('workOrder', fn (Builder $wo) => $wo->whereIn('status', ['released', 'in_progress'])));
    }

    public function confirm(?string $tag, int $machineId, ?int $userId = null, ?string $notes = null, ?int $materialIssueItemId = null): ProductionMaterialReceipt
    {
        return DB::transaction(function () use ($tag, $machineId, $userId, $notes, $materialIssueItemId) {
            if ($materialIssueItemId === null) {
                $ids = $this->issuedItemsForTag($tag ?? '')->pluck('id');
                if ($ids->count() !== 1) {
                    throw ValidationException::withMessages(['tag' => __('Pilih item bon material yang tepat.')]);
                }
                $materialIssueItemId = (int) $ids->sole();
            }
            $issueItem = MaterialIssueItem::query()->find($materialIssueItemId);
            if ($issueItem === null || ($tag !== null && mb_strtolower(trim($tag)) !== mb_strtolower((string) $issueItem->tag))) {
                throw ValidationException::withMessages(['material_issue_item_id' => __('Item bon material tidak valid.')]);
            }
            $issue = MaterialIssue::query()->find($issueItem->material_issue_id);
            $wo = $issue ? WorkOrder::query()->lockForUpdate()->find($issue->work_order_id) : null;
            $issue = $issue ? MaterialIssue::query()->lockForUpdate()->find($issue->id) : null;
            $issueItem = MaterialIssueItem::query()->lockForUpdate()->find($materialIssueItemId);
            if ($issueItem === null || $issue === null || $wo === null
                || $issue->work_order_id !== $wo->id || $issueItem->material_issue_id !== $issue->id
                || ($tag !== null && mb_strtolower(trim($tag)) !== mb_strtolower((string) $issueItem->tag))) {
                throw ValidationException::withMessages(['material_issue_item_id' => __('Item bon material tidak valid.')]);
            }
            $woItem = WorkOrderItem::query()->lockForUpdate()->find($issueItem->work_order_item_id);
            if ($issue?->status !== 'posted' || $wo === null || ! in_array($wo->status, ['released', 'in_progress'], true)
                || $woItem === null || $woItem->work_order_id !== $wo->id || $issueItem->qty <= 0
                || UomCatalog::normalize((string) $issueItem->uom) === null
                || UomCatalog::normalize((string) $woItem->uom_rm) !== UomCatalog::normalize((string) $issueItem->uom)) {
                throw ValidationException::withMessages(['material_issue_item_id' => __('Item bon material tidak valid.')]);
            }
            $machine = Machine::query()->lockForUpdate()->find($machineId);
            MachineRouting::assertEligible($machine, $woItem);
            if (ProductionMaterialReceipt::where('material_issue_item_id', $issueItem->id)->exists()) {
                throw ValidationException::withMessages(['material_issue_item_id' => __('Item bon material sudah diterima.')]);
            }
            $stock = PartStock::query()->lockForUpdate()->find($issueItem->part_stock_id);
            if ($stock === null || $stock->part_id !== $issueItem->part_id
                || mb_strtolower((string) $stock->tag) !== mb_strtolower((string) $issueItem->tag)
                || UomCatalog::normalize((string) $stock->qty_unit) !== UomCatalog::normalize((string) $issueItem->uom)
                || BigDecimal::of($stock->getRawOriginal('qty'))->isLessThan($issueItem->getRawOriginal('qty'))) {
                throw ValidationException::withMessages(['material_issue_item_id' => __('Stok bon material tidak mencukupi atau tidak valid.')]);
            }
            $bookings = WorkOrderMaterialBooking::query()
                ->where('work_order_id', $wo->id)->where('work_order_item_id', $woItem->id)
                ->where('part_id', $issueItem->part_id)->where('part_stock_id', $stock->id)
                ->whereRaw('LOWER(tag) = ?', [mb_strtolower((string) $issueItem->tag)])
                ->where('status', WorkOrderMaterialBooking::STATUS_BOOKED)
                ->orderBy('id')->lockForUpdate()->get();
            if ($bookings->isEmpty() || $bookings->contains(fn ($booking) => $booking->qty <= 0
                || UomCatalog::normalize((string) $booking->uom) !== UomCatalog::normalize((string) $issueItem->uom))
                || $bookings->sum('qty') + 1e-9 < $issueItem->qty) {
                throw ValidationException::withMessages(['material_issue_item_id' => __('Booking bon material tidak mencukupi atau tidak valid.')]);
            }
            $remaining = $issueItem->qty;
            foreach ($bookings as $booking) {
                if ($remaining <= 1e-9) {
                    break;
                }
                $taken = min($remaining, $booking->qty);
                if ($booking->qty - $taken > 1e-9) {
                    $transferred = $booking->replicate();
                    $transferred->fill(['qty' => $taken, 'status' => WorkOrderMaterialBooking::STATUS_TRANSFERRED, 'transferred_at' => now(), 'updated_by' => $userId])->save();
                    $booking->update(['qty' => $booking->qty - $taken, 'updated_by' => $userId]);
                } else {
                    $booking->update(['status' => WorkOrderMaterialBooking::STATUS_TRANSFERRED, 'transferred_at' => now(), 'updated_by' => $userId]);
                }
                $remaining -= $taken;
            }
            $warehouseRemaining = BigDecimal::of($stock->getRawOriginal('qty'))->minus($issueItem->getRawOriginal('qty'));
            PartStock::query()->whereKey($stock->id)->update(['qty' => (string) $warehouseRemaining]);

            return ProductionMaterialReceipt::create([
                'material_issue_item_id' => $issueItem->id,
                'transfer_status' => 'transferred',
                'work_order_id' => $wo->id,
                'work_order_item_id' => $woItem->id,
                'part_stock_id' => $stock->id,
                'qty' => $issueItem->getRawOriginal('qty'),
                'qty_consumed' => 0,
                'uom' => UomCatalog::normalize((string) $issueItem->uom),
                'invoice' => $issueItem->invoice,
                'supplier' => $issueItem->supplier,
                'tag' => $issueItem->tag,
                'part_id' => $issueItem->part_id,
                'machine_id' => $machineId,
                'received_by' => $userId,
                'received_at' => now(),
                'notes' => $notes,
            ]);
        });
    }

    /**
     * Daftar penerimaan untuk sebuah mesin pada tanggal tertentu.
     *
     * @return list<ProductionMaterialReceipt>
     */
    public function receiptsByMachine(int $machineId, ?string $date = null): array
    {
        $targetDate = $date ?? Carbon::now((string) config('app.timezone'))->toDateString();

        return ProductionMaterialReceipt::query()
            ->where('machine_id', $machineId)
            ->whereDate('received_at', $targetDate)
            ->with(['part:id,part_number,part_name,model,size', 'receiver:id,name'])
            ->orderByDesc('received_at')
            ->get()
            ->all();
    }
}
