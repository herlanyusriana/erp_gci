<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Stok FG dipotong sekali saat barang dipindah ke area pengiriman.
        // Penanda ini mencegah potongan ganda ketika QC menolak lalu barang
        // dipindahkan ulang.
        Schema::table('deliveries', function (Blueprint $table) {
            $table->timestamp('stock_deducted_at')->nullable()->after('picked_at');
        });
    }

    public function down(): void
    {
        Schema::table('deliveries', function (Blueprint $table) {
            $table->dropColumn('stock_deducted_at');
        });
    }
};
