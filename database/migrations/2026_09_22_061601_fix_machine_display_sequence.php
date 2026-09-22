<?php

use App\Models\Machine;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Mesin di luar daftar (mis. Subcon) tidak ikut urutan papan.
        DB::table('machines')->whereNull('deleted_at')->update(['sequence' => null]);

        foreach (Machine::DISPLAY_SEQUENCE as $name => $sequence) {
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
