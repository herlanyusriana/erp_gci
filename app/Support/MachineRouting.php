<?php

namespace App\Support;

use App\Models\Machine;
use App\Models\ProductionPlanItem;
use App\Models\WorkOrderItem;
use Illuminate\Validation\ValidationException;

class MachineRouting
{
    public static function groupKey(?Machine $machine, ?int $machineId = null): string
    {
        $name = strtoupper(trim((string) $machine?->machine_name));

        return $name !== '' ? substr($name, 0, 3) : ($machineId !== null ? 'id:'.$machineId : 'none');
    }

    /**
     * Cek apakah mesin merupakan lini Tandem Press Line (TPL) yang menggabungkan
     * beberapa step berurutan (mis. WIP1 -> WIP2 -> WIP3) menjadi satu kali jalan.
     * Khusus untuk TPL DONGSHIN dan TPL KUKIL.
     */
    public static function isTplPressLine(?Machine $machine): bool
    {
        if ($machine === null) {
            return false;
        }

        $name = strtoupper(trim((string) $machine->machine_name));
        $code = strtoupper(trim((string) $machine->machine_code));

        return in_array($name, ['TPL DONGSHIN', 'TPL KUKIL'], true)
            || in_array($code, ['TPL_DONGSHIN', 'TPL_KUKIL'], true);
    }

    public static function isPlannedForItem(Machine $machine, WorkOrderItem $item): bool
    {
        if ($item->work_order_id === null) {
            return false;
        }

        if ($item->parent_part_id === null && $item->machine_id === null) {
            return false;
        }

        return ProductionPlanItem::query()
            ->where('work_order_id', $item->work_order_id)
            ->where('machine_id', $machine->id)
            ->where('target_d', '>', 0)
            ->where(function ($query) use ($item): void {
                if ($item->parent_part_id !== null) {
                    $query->where('wip_part_id', $item->parent_part_id);
                }
                if ($item->machine_id !== null) {
                    if ($item->parent_part_id !== null) {
                        $query->orWhere('origin_machine_id', $item->machine_id);
                    } else {
                        $query->where('origin_machine_id', $item->machine_id);
                    }
                }
            })
            ->exists();
    }

    public static function assertEligible(?Machine $machine, WorkOrderItem $item): void
    {
        if ($machine === null || ! $machine->is_active) {
            throw ValidationException::withMessages(['machine_id' => __('Mesin tidak ditemukan atau tidak aktif.')]);
        }
        if ($item->machine_id !== null
            && self::groupKey($item->machine, $item->machine_id) !== self::groupKey($machine, $machine->id)
            && ! self::isPlannedForItem($machine, $item)) {
            throw ValidationException::withMessages(['machine_id' => __('Mesin tidak sesuai grup routing item WO.')]);
        }
    }
}
