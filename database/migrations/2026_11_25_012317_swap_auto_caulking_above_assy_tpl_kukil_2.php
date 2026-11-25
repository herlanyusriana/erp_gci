<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('machines')
            ->whereNull('deleted_at')
            ->whereIn(DB::raw('UPPER(TRIM(machine_name))'), [
                'ASSY. TPL KUKIL 1',
                'ASSY TPL KUKIL 1',
                'ASSY. TPL COMP BASE 1',
            ])
            ->update(['sequence' => 2, 'updated_at' => $now]);

        DB::table('machines')
            ->whereNull('deleted_at')
            ->whereRaw('UPPER(TRIM(machine_name)) = ?', ['AUTO CAULKING'])
            ->update(['sequence' => 3, 'updated_at' => $now]);

        DB::table('machines')
            ->whereNull('deleted_at')
            ->whereIn(DB::raw('UPPER(TRIM(machine_name))'), [
                'ASSY. TPL KUKIL 2',
                'ASSY TPL KUKIL 2',
                'ASSY. TPL COMP BASE 2',
            ])
            ->update(['sequence' => 4, 'updated_at' => $now]);
    }

    public function down(): void
    {
        $now = now();

        DB::table('machines')
            ->whereNull('deleted_at')
            ->whereIn(DB::raw('UPPER(TRIM(machine_name))'), [
                'ASSY. TPL KUKIL 2',
                'ASSY TPL KUKIL 2',
                'ASSY. TPL COMP BASE 2',
            ])
            ->update(['sequence' => 3, 'updated_at' => $now]);

        DB::table('machines')
            ->whereNull('deleted_at')
            ->whereRaw('UPPER(TRIM(machine_name)) = ?', ['AUTO CAULKING'])
            ->update(['sequence' => 4, 'updated_at' => $now]);
    }
};
