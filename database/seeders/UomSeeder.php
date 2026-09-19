<?php

namespace Database\Seeders;

use App\Models\Uom;
use Illuminate\Database\Seeder;

class UomSeeder extends Seeder
{
    /**
     * Master satuan resmi. Idempotent: updateOrCreate pada `code`.
     *
     * Ditambahkan dari daftar lama yang sebelumnya hanya hidup sebagai string
     * hardcode (PALLET, COIL, SET, EA, UOM, KG) agar semua tabel transaksi
     * punya rujukan master yang sama.
     */
    public function run(): void
    {
        $uoms = [
            ['code' => 'KGM', 'name' => 'Kilogram', 'is_active' => true],
            ['code' => 'KG', 'name' => 'Kilogram (alias)', 'is_active' => true],
            ['code' => 'PCS', 'name' => 'Pieces', 'is_active' => true],
            ['code' => 'ROLL', 'name' => 'Roll', 'is_active' => true],
            ['code' => 'SHEET', 'name' => 'Sheet', 'is_active' => true],
            ['code' => 'COIL', 'name' => 'Coil', 'is_active' => true],
            ['code' => 'SET', 'name' => 'Set', 'is_active' => true],
            ['code' => 'EA', 'name' => 'Each', 'is_active' => true],
            ['code' => 'UOM', 'name' => 'Unspecified', 'is_active' => true],
            ['code' => 'PALLET', 'name' => 'Pallet', 'is_active' => true],
            ['code' => 'BUNDLE', 'name' => 'Bundle', 'is_active' => true],
            ['code' => 'BOX', 'name' => 'Box', 'is_active' => true],
            ['code' => 'BAG', 'name' => 'Bag', 'is_active' => true],
            ['code' => 'PACKAGES', 'name' => 'Packages', 'is_active' => true],
        ];

        foreach ($uoms as $uom) {
            Uom::updateOrCreate(['code' => $uom['code']], $uom);
        }
    }
}
