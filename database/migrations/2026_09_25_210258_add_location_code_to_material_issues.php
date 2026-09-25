<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Lokasi rak yang dipindai operator saat mengeluarkan material.
        // Disimpan sebagai teks (bukan foreign key) agar riwayat lama tetap sah
        // walau lokasinya kelak dinonaktifkan atau diganti namanya.
        Schema::table('material_issues', function (Blueprint $table) {
            $table->string('location_code')->nullable()->after('received_by');
            $table->index('location_code');
        });
    }

    public function down(): void
    {
        Schema::table('material_issues', function (Blueprint $table) {
            $table->dropIndex(['location_code']);
            $table->dropColumn('location_code');
        });
    }
};
