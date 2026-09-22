<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Urutan tampil mesin di papan Production Plan — mengikuti urutan master
     * (kolom "No" pada file produksi). Urutan = kemunculan pertama tiap mesin;
     * mesin yang tidak ada di daftar ditaruh paling bawah.
     *
     * @var list<string>
     */
    private array $order = [
        'TPL KUKIL',
        'ASSY. TPL COMP BASE 1',
        'ASSY. TPL COMP BASE 2',
        'AUTO CAULKING',
        'SPOT WELDING 4 -  40 KVA',
        'SPOT WELDING 3 -  40 KVA',
        'SPOT WELDING 1 -  40 KVA',
        'ASSEMBLING RF',
        'TPL DONGSHIN',
        'ASSY. TPL DONGSHIN',
        'ASSEMBLING SMALL PART',
        'TAPPING 2',
        'AIDA 100 TON',
        'SHANGYANG 25 TON',
        'WASINO 150 TON',
        'HANOUL 250 TON',
        'KUKIL 110 TON',
        'KUKIL 160 TON',
        'JENJI 200 TON',
        'KYOKUTO 300 TON',
        'AIDA 60 TON',
        'KUKIL 400 TON',
        'SPOT WELDING 5 - 35 KVA',
        'WELDING ROBOT',
        // Tidak terlihat di master → paling bawah
        'SPOT WELDING 2 -  40 KVA',
        'BARREL',
    ];

    public function up(): void
    {
        foreach ($this->order as $index => $name) {
            DB::table('machines')
                ->whereNull('deleted_at')
                ->where('machine_name', $name)
                ->update(['sequence' => $index + 1, 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        DB::table('machines')->update(['sequence' => null]);
    }
};
