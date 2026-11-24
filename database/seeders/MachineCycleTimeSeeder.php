<?php

namespace Database\Seeders;

use App\Models\Machine;
use App\Models\MachineCycleTime;
use App\Models\Part;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;

class MachineCycleTimeSeeder extends Seeder
{
    public function run(): void
    {
        $path = database_path('seeders/data/machine_cycle_times.json');
        abort_unless(File::exists($path), 500, "Missing machine_cycle_times.json at {$path}.");

        $rows = json_decode(File::get($path), true);
        if (! is_array($rows)) {
            return;
        }

        $systemUser = User::where('email', 'admin@geumcheon.local')->first()?->id;

        $machinesByName = Machine::withTrashed()
            ->get(['id', 'machine_name'])
            ->keyBy(fn (Machine $m): string => $this->normalizeMachineName($m->machine_name));

        $partsByNumber = Part::withTrashed()
            ->get(['id', 'part_number'])
            ->keyBy(fn (Part $p): string => strtoupper(trim($p->part_number)));

        foreach ($rows as $row) {
            $machineName = $this->normalizeMachineName((string) ($row['machine_name'] ?? ''));
            $partNumber = strtoupper(trim((string) ($row['part_number'] ?? '')));
            $seconds = $row['cycle_time_seconds'] ?? null;

            if ($machineName === '' || $partNumber === '' || ! is_numeric($seconds) || (float) $seconds <= 0) {
                continue;
            }

            $machine = $machinesByName->get($machineName);
            $part = $partsByNumber->get($partNumber);
            if ($machine === null || $part === null) {
                continue;
            }

            $qdc = $row['qdc_minutes'] ?? null;

            MachineCycleTime::updateOrCreate(
                [
                    'machine_id' => $machine->id,
                    'part_id' => $part->id,
                ],
                [
                    'cycle_time_seconds' => (float) $seconds,
                    'qdc_minutes' => (is_numeric($qdc) && (float) $qdc >= 0) ? (float) $qdc : null,
                    'is_active' => true,
                    'created_by' => $systemUser,
                    'updated_by' => $systemUser,
                ],
            );
        }
    }

    private function normalizeMachineName(string $name): string
    {
        return strtoupper((string) preg_replace('/\s+/', ' ', trim($name)));
    }
}
