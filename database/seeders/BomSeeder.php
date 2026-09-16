<?php

namespace Database\Seeders;

use App\Models\Bom;
use App\Models\BomItem;
use App\Models\Machine;
use App\Models\Part;
use App\Models\Process;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;

class BomSeeder extends Seeder
{
    public function run(): void
    {
        $path = database_path('seeders/data/bom.json');
        abort_unless(File::exists($path), 500, "Missing bom.json at {$path}. Run scripts/extract_bom.py first.");

        $data = json_decode(File::get($path), true);
        $systemUser = User::where('email', 'admin@geumcheon.local')->first()?->id;

        $parts = Part::withTrashed()->pluck('id', 'part_number');
        $processes = Process::pluck('id', 'process_name');
        $machines = Machine::pluck('id', 'machine_name');

        // idempotent: wipe and rebuild BOM
        Bom::query()->forceDelete();

        $bomByFg = [];
        foreach ($data['boms'] as $b) {
            $partId = $parts[$b['part_number']] ?? null;
            if ($partId === null) {
                continue;
            }
            $bomByFg[$b['part_number']] = Bom::create([
                'part_id' => $partId,
                'bom_no' => $b['bom_no'] ?? null,
                'version' => '1.0',
                'is_active' => true,
                'created_by' => $systemUser,
            ]);
        }

        $cachedPart = [];
        foreach ($data['items'] as $it) {
            $bom = $bomByFg[$it['fg_part_number']] ?? null;
            if ($bom === null) {
                continue;
            }

            $parentId = $this->resolvePart($it['parent_part_number'] ?? null, $parts, $cachedPart);
            $childId = $this->resolvePart($it['child_part_number'] ?? null, $parts, $cachedPart);

            BomItem::create([
                'bom_id' => $bom->id,
                'sequence' => $it['sequence'] ?? null,
                'process_id' => isset($it['process_name']) ? ($processes[$it['process_name']] ?? null) : null,
                'machine_id' => isset($it['machine_name']) ? ($machines[$it['machine_name']] ?? null) : null,
                'parent_part_id' => $parentId,
                'parent_part_name' => $it['parent_part_name'] ?? null,
                'parent_qty' => $it['parent_qty'] ?? null,
                'parent_uom' => $it['parent_uom'] ?? null,
                'child_part_id' => $childId,
                'child_part_name' => $it['child_part_name'] ?? null,
                'size' => $it['size'] ?? null,
                'child_qty' => $it['child_qty'] ?? null,
                'uom_rm' => $it['uom_rm'] ?? null,
                'special_code' => $it['special_code'] ?? null,
                'source' => $it['source'] ?? null,
                'is_active' => true,
                'created_by' => $systemUser,
            ]);
        }
    }

    private function resolvePart(?string $number, $parts, array &$cache): ?int
    {
        if ($number === null || $number === '') {
            return null;
        }
        if (! array_key_exists($number, $cache)) {
            $cache[$number] = $parts[$number] ?? null;
        }

        return $cache[$number];
    }
}