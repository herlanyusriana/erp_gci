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
