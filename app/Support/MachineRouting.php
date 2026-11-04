<?php

namespace App\Support;

use App\Models\Machine;
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

    public static function assertEligible(?Machine $machine, WorkOrderItem $item): void
    {
        if ($machine === null || ! $machine->is_active) {
            throw ValidationException::withMessages(['machine_id' => __('Mesin tidak ditemukan atau tidak aktif.')]);
        }
        if ($item->machine_id !== null
            && self::groupKey($item->machine, $item->machine_id) !== self::groupKey($machine, $machine->id)) {
            throw ValidationException::withMessages(['machine_id' => __('Mesin tidak sesuai grup routing item WO.')]);
        }
    }
}
