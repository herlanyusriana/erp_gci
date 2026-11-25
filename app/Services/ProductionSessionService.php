<?php

namespace App\Services;

use App\Models\ConfigMaster;
use App\Models\Machine;
use App\Models\MachineDowntime;
use App\Models\ProductionPlanItem;
use App\Models\ProductionResult;
use App\Models\WorkOrderItem;
use App\Support\MachineRouting;
use App\Support\ProductionPeriods;
use Illuminate\Support\Carbon;

/**
 * Konteks satu sesi produksi di sebuah mesin: shift yang sedang berjalan,
 * periode yang sudah boleh dilaporkan, dan part hasil yang dikerjakan.
 *
 * Shift diturunkan dari jam pabrik (bukan jam perangkat), karena operator bisa
 * membawa HP dengan zona waktu berbeda.
 */
final class ProductionSessionService
{
    public function plantNow(): Carbon
    {
        $zone = (string) ConfigMaster::getValue('SYSTEM', 'timezone', 'Asia/Jakarta');

        return now()->setTimezone($zone);
    }

    public function currentShift(Carbon $nowLocal): string
    {
        return ProductionPeriods::currentShift($nowLocal, $nowLocal->timezoneName);
    }

    /**
     * @return array{machine: array<string, mixed>, shift: string, shifts: list<string>, periods: list<array<string, mixed>>, parts: list<array<string, mixed>>, downtimes: list<array<string, mixed>>}
     */
    public function context(Machine $machine, ?string $shift = null): array
    {
        $zone = (string) ConfigMaster::getValue('SYSTEM', 'timezone', 'Asia/Jakarta');
        $nowLocal = $this->plantNow();

        // Operator boleh memilih shift (mis. mengisi laporan susulan shift
        // sebelumnya); tanpa pilihan, pakai shift yang sedang berjalan.
        $shift = $shift !== null && array_key_exists($shift, ProductionPeriods::SHIFTS)
            ? $shift
            : $this->currentShift($nowLocal);

        $partsQuery = WorkOrderItem::query()
            ->where('machine_id', $machine->id)
            ->whereNotNull('parent_part_id')
            ->with(['parentPart:id,part_number,part_name,model', 'workOrder:id,wo_no,status,qty'])
            ->whereHas('workOrder', fn ($q) => $q->whereIn('status', ['in_progress']))
            ->get();

        // Khusus untuk TPL DONGSHIN dan TPL KUKIL: lini tandem continuous
        // menghasilkan output langsung pada WIP paling akhir. Step perantara
        // (WIP1, WIP2, dst.) tidak dilaporkan terpisah, sehingga disembunyikan
        // dari dropdown part operator.
        if (MachineRouting::isTplPressLine($machine)) {
            $partsQuery = $partsQuery->groupBy('work_order_id')->flatMap(function ($woItems) {
                $childIdsOnMachine = $woItems->pluck('child_part_id')->filter()->unique()->all();

                return $woItems->reject(fn ($item) => in_array($item->parent_part_id, $childIdsOnMachine, true));
            });
        }

        $plannedReassigned = ProductionPlanItem::query()
            ->where('machine_id', $machine->id)
            ->where('target_d', '>', 0)
            ->whereNotNull('work_order_id')
            ->whereNotNull('wip_part_id')
            ->get(['work_order_id', 'wip_part_id']);

        if ($plannedReassigned->isNotEmpty()) {
            $plannedPairSet = [];
            $plannedWoIds = [];
            $plannedWipIds = [];
            foreach ($plannedReassigned as $pi) {
                $wId = (int) $pi->work_order_id;
                $wipId = (int) $pi->wip_part_id;
                $plannedPairSet[$wId.'#'.$wipId] = true;
                $plannedWoIds[$wId] = true;
                $plannedWipIds[$wipId] = true;
            }

            $reassignedItems = WorkOrderItem::query()
                ->whereIn('work_order_id', array_keys($plannedWoIds))
                ->whereIn('parent_part_id', array_keys($plannedWipIds))
                ->with(['parentPart:id,part_number,part_name,model', 'workOrder:id,wo_no,status,qty'])
                ->whereHas('workOrder', fn ($q) => $q->whereIn('status', ['in_progress']))
                ->get()
                ->filter(fn (WorkOrderItem $item): bool => isset($plannedPairSet[$item->work_order_id.'#'.$item->parent_part_id]));

            if ($reassignedItems->isNotEmpty()) {
                $partsQuery = $partsQuery->concat($reassignedItems);
            }
        }

        // Sisa rencana per part pada WO-nya: target = qty WO, produced = total
        // good yang sudah dilaporkan. APK memakai ini hanya untuk memperingatkan;
        // server tetap penentu akhir.
        $producedByStep = $partsQuery->isNotEmpty()
            ? ProductionResult::query()
                ->whereIn('work_order_id', $partsQuery->pluck('work_order_id')->unique()->all())
                ->whereIn('parent_part_id', $partsQuery->pluck('parent_part_id')->unique()->all())
                ->selectRaw('work_order_id, parent_part_id, SUM(qty_good) AS produced')
                ->groupBy('work_order_id', 'parent_part_id')
                ->get()
                ->keyBy(fn (ProductionResult $row): string => $row->work_order_id.'#'.$row->parent_part_id)
            : collect();

        $parts = $partsQuery
            ->unique(fn (WorkOrderItem $item): string => $item->work_order_id.'#'.$item->parent_part_id)
            ->map(function (WorkOrderItem $item) use ($producedByStep): array {
                $target = (float) ($item->workOrder->qty ?? 0);
                $produced = (float) ($producedByStep[$item->work_order_id.'#'.$item->parent_part_id]->produced ?? 0);

                return [
                    'part_id' => (int) $item->parent_part_id,
                    'part_number' => $item->parentPart?->part_number,
                    'part_name' => $item->parentPart?->part_name,
                    'model' => $item->parentPart?->model,
                    'uom' => $item->parent_uom,
                    'work_order_id' => (int) $item->work_order_id,
                    'wo_no' => $item->workOrder?->wo_no,
                    'target_qty' => $target,
                    'produced_qty' => $produced,
                    'remaining_qty' => max(0.0, $target - $produced),
                ];
            })
            ->values()
            ->all();

        $sessionCarbon = ProductionPeriods::sessionDate($shift, now(), $zone) ?? $nowLocal->copy()->startOfDay();
        $sessionDate = $sessionCarbon->toDateString();
        $schedule = ProductionPeriods::scheduleFor($shift, $sessionCarbon);

        $downtimes = MachineDowntime::query()
            ->where('machine_id', $machine->id)
            ->whereDate('downtime_date', $sessionDate)
            ->where('shift', $shift)
            ->orderBy('start_time')
            ->orderBy('id')
            ->get()
            ->map(fn (MachineDowntime $row): array => [
                'id' => (int) $row->id,
                'category' => (string) $row->category,
                'reason' => (string) $row->reason,
                'start_time' => substr((string) $row->start_time, 0, 5),
                'end_time' => substr((string) $row->end_time, 0, 5),
                'duration_minutes' => (int) $row->duration_minutes,
                'notes' => $row->notes,
                'operator_name' => $row->operator_name,
                'work_order_id' => $row->work_order_id !== null ? (int) $row->work_order_id : null,
                'part_id' => $row->part_id !== null ? (int) $row->part_id : null,
            ])
            ->values()
            ->all();

        return [
            'machine' => [
                'id' => (int) $machine->id,
                'machine_code' => $machine->machine_code,
                'machine_name' => $machine->machine_name,
            ],
            'shift' => $shift,
            'shifts' => array_map('strval', array_keys(ProductionPeriods::SHIFTS)),
            'session_date' => $sessionDate,
            'day_type' => $schedule['day_type'] ?? ProductionPeriods::DAY_WEEKDAY,
            'schedule' => $schedule,
            'periods' => ProductionPeriods::withStatusForShift($shift, now(), $zone),
            'parts' => $parts,
            'downtimes' => $downtimes,
        ];
    }
}
