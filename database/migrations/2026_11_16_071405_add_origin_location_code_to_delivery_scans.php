<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Lokasi asal tag saat dipindah, supaya pengembalian (QC tolak atau
        // batal) mengembalikan barang ke tempat semula.
        Schema::table('delivery_scans', function (Blueprint $table) {
            $table->string('origin_location_code', 50)->nullable()->after('qty');
        });
    }

    public function down(): void
    {
        Schema::table('delivery_scans', function (Blueprint $table) {
            $table->dropColumn('origin_location_code');
        });
    }
};
