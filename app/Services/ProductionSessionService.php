<?php

namespace App\Services;

use App\Models\ConfigMaster;
use App\Models\Machine;
use App\Models\WorkOrderItem;
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
     * @return array{machine: array<string, mixed>, shift: string, shifts: list<string>, periods: list<array<string, mixed>>, parts: list<array<string, mixed>>}
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

        $parts = WorkOrderItem::query()
            ->where('machine_id', $machine->id)
            ->whereNotNull('parent_part_id')
            ->with(['parentPart:id,part_number,part_name', 'workOrder:id,wo_no,status'])
            ->whereHas('workOrder', fn ($q) => $q->whereIn('status', ['in_progress']))
            ->get()
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

        return [
            'machine' => [
                'id' => (int) $machine->id,
                'machine_code' => $machine->machine_code,
                'machine_name' => $machine->machine_name,
            ],
            'shift' => $shift,
            'shifts' => array_map('strval', array_keys(ProductionPeriods::SHIFTS)),
            'periods' => ProductionPeriods::reportableForShift($shift, now(), $zone),
            'parts' => $parts,
        ];
    }

    private function toMinutes(string $clock): int
    {
        [$hour, $minute] = array_map('intval', explode(':', $clock));

        return ($hour * 60) + $minute;
    }
}
