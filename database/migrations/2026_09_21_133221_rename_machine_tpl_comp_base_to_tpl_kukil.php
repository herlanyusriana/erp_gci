<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Rename mesin "TPL COMP BASE" → "TPL KUKIL".
     * BOM/routing & baris plan mereferensikan mesin lewat id, jadi ikut otomatis.
     */
    public function up(): void
    {
        if (DB::table('machines')->whereNull('deleted_at')->where('machine_name', 'TPL KUKIL')->exists()) {
            return;
        }

        DB::table('machines')
            ->whereNull('deleted_at')
            ->where('machine_name', 'TPL COMP BASE')
            ->update([
                'machine_name' => 'TPL KUKIL',
                'machine_code' => 'TPL_KUKIL',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('machines')
            ->whereNull('deleted_at')
            ->where('machine_name', 'TPL KUKIL')
            ->where('machine_code', 'TPL_KUKIL')
            ->update([
                'machine_name' => 'TPL COMP BASE',
                'machine_code' => 'TPL_COMP_BASE',
                'updated_at' => now(),
            ]);
    }
};
