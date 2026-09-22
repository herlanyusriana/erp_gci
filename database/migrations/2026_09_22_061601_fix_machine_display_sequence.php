<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Urutan tampil mesin di papan Production Plan — sesuai daftar master
     * (kolom "No."). Nomor dipakai apa adanya sebagai `sequence` sehingga celah
     * (mis. No. 13 kosong, No. 14/17 belum ada mesinnya) tetap terjaga.
     *
     * @var array<string, int>
     */
    private array $order = [
        'TPL KUKIL' => 1,
        'ASSY. TPL COMP BASE 1' => 2,
        'AUTO CAULKING' => 3,
        'ASSY. TPL COMP BASE 2' => 4,
        'SPOT WELDING 4 -  40 KVA' => 5,
        'SPOT WELDING 3 -  40 KVA' => 6,
        'SPOT WELDING 2 -  40 KVA' => 7,
        'SPOT WELDING 1 -  40 KVA' => 8,
        'ASSEMBLING RF' => 9,
        'TPL DONGSHIN' => 10,
        'ASSY. TPL DONGSHIN' => 11,
        'ASSEMBLING SMALL PART' => 12,
        // 13 = kosong
        // 14 = AMADA 150 TON (belum ada di master mesin)
        'SHANGYANG 25 TON' => 15,
        'WASINO 150 TON' => 16,
        // 17 = NOGUCHI 80 TON (belum ada di master mesin)
        'KUKIL 110 TON' => 18,
        'KUKIL 160 TON' => 19,
        'JENJI 200 TON' => 20,
        'KYOKUTO 300 TON' => 21,
        'AIDA 60 TON' => 22,
        'AIDA 100 TON' => 23,
        'HANOUL 250 TON' => 24,
        'KUKIL 400 TON' => 25,
        'SPOT WELDING 5 - 35 KVA' => 26,
        'WELDING ROBOT' => 27,
        'BARREL' => 28,
        'TAPPING 2' => 29,
    ];

    public function up(): void
    {
        // Mesin di luar daftar (mis. Subcon) tidak ikut urutan papan.
        DB::table('machines')->whereNull('deleted_at')->update(['sequence' => null]);

        foreach ($this->order as $name => $sequence) {
            DB::table('machines')
                ->whereNull('deleted_at')
                ->where('machine_name', $name)
                ->update(['sequence' => $sequence, 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        DB::table('machines')->whereNull('deleted_at')->update(['sequence' => null]);
    }
};
