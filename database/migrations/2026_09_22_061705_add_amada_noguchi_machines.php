<?php

use App\Models\Machine;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Tambah mesin yang ada di daftar master produksi tapi belum ada di master
     * mesin: AMADA 150 TON (No. 14) & NOGUCHI 80 TON (No. 17).
     */
    public function up(): void
    {
        foreach (['AMADA 150 TON' => 'AMADA_150_TON', 'NOGUCHI 80 TON' => 'NOGUCHI_80_TON'] as $name => $code) {
            Machine::withTrashed()->firstOrCreate(
                ['machine_code' => $code],
                ['machine_name' => $name, 'is_active' => true],
            );
        }

        // Terapkan ulang seluruh urutan papan (termasuk mesin baru).
        foreach (Machine::DISPLAY_SEQUENCE as $name => $sequence) {
            Machine::withTrashed()
                ->where('machine_name', $name)
                ->update(['sequence' => $sequence]);
        }
    }

    public function down(): void
    {
        Machine::withTrashed()
            ->whereIn('machine_code', ['AMADA_150_TON', 'NOGUCHI_80_TON'])
            ->forceDelete();
    }
};
