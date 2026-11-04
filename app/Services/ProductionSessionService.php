<?php

namespace App\Services;

use App\Models\ConfigMaster;
use App\Models\Machine;
use App\Models\MachineDowntime;
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
        $minutes = ((int) $nowLocal->format('H') * 60) + (int) $nowLocal->format('i');

        foreach (ProductionPeriods::SHIFTS as $shift => $config) {
            $start = $this->toMinutes($config['start']);
            $end = $this->toMinutes($config['end']);

            if ($start <= $end) {
                if ($minutes >= $start && $minutes < $end) {
                    return (string) $shift;
                }

                continue;
            }

            // Shift lintas tengah malam (shift 3).
            if ($minutes >= $start || $minutes < $end) {
                return (string) $shift;
            }
        }

        return '1';
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
            ->with(['parentPart:id,part_number,part_name', 'workOrder:id,wo_no,status'])
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

        $parts = $partsQuery
            ->unique('parent_part_id')
            ->map(fn (WorkOrderItem $item): array => [
                'part_id' => (int) $item->parent_part_id,
                'part_number' => $item->parentPart?->part_number,
                'part_name' => $item->parentPart?->part_name,
                'uom' => $item->parent_uom,
                'work_order_id' => (int) $item->work_order_id,
                'wo_no' => $item->workOrder?->wo_no,
            ])
            ->values()
            ->all();

        $sessionDate = ProductionPeriods::sessionDate($shift, now(), $zone)?->toDateString()
            ?? $nowLocal->toDateString();

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
            // Semua periode dikirim beserta statusnya. Layar menampilkan 7
            // periode penuh agar operator melihat shift utuh dan tahu mana yang
            // belum boleh dilaporkan, alih-alih melihat daftar yang terpotong.
            'periods' => ProductionPeriods::withStatusForShift($shift, now(), $zone),
            'parts' => $parts,
            'downtimes' => $downtimes,
        ];
    }

    private function toMinutes(string $clock): int
    {
        [$hour, $minute] = array_map('intval', explode(':', $clock));

        return ($hour * 60) + $minute;
    }
}
