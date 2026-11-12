<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Waktu barang benar-benar dipindah ke area pengiriman. Dipakai kartu
        // stok WIP & FG untuk menentukan tanggal "issue" barang jadi.
        Schema::table('deliveries', function (Blueprint $table) {
            $table->timestamp('picked_at')->nullable()->after('shipped_at');
        });
    }

    public function down(): void
    {
        Schema::table('deliveries', function (Blueprint $table) {
            $table->dropColumn('picked_at');
        });
    }
};
