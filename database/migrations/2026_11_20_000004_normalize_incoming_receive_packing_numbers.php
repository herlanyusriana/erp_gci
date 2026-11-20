<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            UPDATE incoming_receive_packings AS p
            SET packing_no = TRIM(r.tag) || '-' || p.sequence
            FROM incoming_receives AS r
            WHERE p.receive_id = r.id
              AND r.tag IS NOT NULL
              AND TRIM(r.tag) <> ''
              AND p.packing_no <> (TRIM(r.tag) || '-' || p.sequence)
        ");
    }

    public function down(): void
    {
        // Penormalan satu arah ke format {TAG}-{sequence}.
    }
};
