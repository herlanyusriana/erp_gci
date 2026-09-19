<?php

namespace Database\Seeders;

use App\Models\Machine;
use App\Models\Part;
use App\Models\PartSubstitute;
use App\Models\PartType;
use App\Models\Process;
use App\Models\Supplier;
use App\Models\Uom;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;

class MasterDataSeeder extends Seeder
{
    /**
     * Seed master data from the extracted JSON snapshot.
     * Idempotent: uses updateOrCreate keyed on the business keys.
     */
    public function run(): void
    {
        $path = database_path('seeders/data/master_data.json');
        abort_unless(File::exists($path), 500, "Missing master_data.json snapshot at {$path}. Run scripts/extract_master_data.py first.");

        $data = json_decode(File::get($path), true);

        $systemUser = User::where('email', 'admin@geumcheon.local')->first()?->id;

        // Part types
        $typeIds = [];
        foreach ($data['part_types'] as $type) {
            $typeIds[$type['code']] = PartType::updateOrCreate(
                ['code' => $type['code']],
                ['name' => $type['name']],
            )->id;
        }

        // UOMs — master resmi di-seed oleh UomSeeder; di sini hanya lookup.
        $uomIds = [];
        foreach ($data['uoms'] as $code) {
            $uomIds[$code] = Uom::firstOrCreate(
                ['code' => strtoupper($code)],
                ['name' => strtoupper($code), 'is_active' => true],
            )->id;
        }

        // Parts
        $partIds = [];
        foreach ($data['parts'] as $p) {
            $part = Part::withTrashed()
                ->updateOrCreate(
                    ['part_number' => $p['part_number']],
                    [
                        'part_name' => $p['part_name'],
                        'hs_code' => $p['hs_code'] ?? null,
                        'part_type_id' => $typeIds[$p['part_type']] ?? null,
                        'model' => $p['model'],
                        'uom_id' => isset($p['uom']) ? ($uomIds[$p['uom']] ?? null) : null,
                        'size' => $p['size'],
                        'nett_weight' => $p['nett_weight'],
                        'is_active' => true,
                        'created_by' => $systemUser,
                    ],
                );

            if ($part->trashed()) {
                $part->restore();
            }
            $partIds[$p['part_number']] = $part->id;
        }

        // Suppliers
        $supplierIds = [];
        foreach ($data['suppliers'] as $name) {
            $code = $this->slugCode($name);
            $supplierIds[$name] = Supplier::withTrashed()->updateOrCreate(
                ['supplier_code' => $code],
                ['supplier_name' => $name, 'is_active' => true, 'created_by' => $systemUser],
            )->id;
        }

        // Substitutes
        foreach ($data['substitutes'] as $sub) {
            $partId = $partIds[$sub['part_number']] ?? null;
            $subPartId = $partIds[$sub['substitute_part_number']] ?? null;
            $supplierId = isset($sub['supplier_name']) ? ($supplierIds[$sub['supplier_name']] ?? null) : null;

            if ($partId === null || $subPartId === null || $supplierId === null) {
                continue;
            }

            PartSubstitute::updateOrCreate(
                [
                    'part_id' => $partId,
                    'substitute_part_id' => $subPartId,
                    'supplier_id' => $supplierId,
                ],
                [
                    'material_group' => $sub['material_group'] ?? null,
                    'source' => $sub['source'] ?? null,
                    'is_active' => true,
                    'created_by' => $systemUser,
                ],
            );
        }

        // Machines
        $machineIds = [];
        foreach ($data['machines'] as $name) {
            $machineIds[$name] = Machine::withTrashed()->updateOrCreate(
                ['machine_code' => $this->slugCode($name)],
                ['machine_name' => $name, 'is_active' => true, 'created_by' => $systemUser],
            )->id;
        }

        // Processes
        $processIds = [];
        foreach ($data['processes'] as $name) {
            $processIds[$name] = Process::withTrashed()->updateOrCreate(
                ['process_code' => $this->slugCode($name)],
                ['process_name' => $name, 'is_active' => true, 'created_by' => $systemUser],
            )->id;
        }

        // Machine ↔ Process mapping
        foreach ($data['machine_process'] as $mp) {
            if (! isset($machineIds[$mp['machine_name']], $processIds[$mp['process_name']])) {
                continue;
            }

            Machine::find($machineIds[$mp['machine_name']])
                ?->processes()
                ->syncWithoutDetaching([$processIds[$mp['process_name']]]);
        }
    }

    private function slugCode(string $name): string
    {
        $slug = preg_replace('/[^A-Za-z0-9]+/', '_', trim($name));
        $slug = trim((string) $slug, '_');
        $slug = strtoupper($slug);

        return $slug !== '' ? $slug : 'X'.substr(md5($name), 0, 6);
    }
}
