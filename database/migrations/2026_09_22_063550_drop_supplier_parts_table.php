<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Part per supplier diambil dari `part_substitutes.supplier_id`
     * (sumber tunggal) — tabel terpisah tidak dipakai.
     */
    public function up(): void
    {
        Schema::dropIfExists('supplier_parts');
    }

    public function down(): void
    {
        // Tidak dipulihkan (data mapping tinggal di part_substitutes).
    }
};
