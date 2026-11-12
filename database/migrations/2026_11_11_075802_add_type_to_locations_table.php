<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tipe lokasi memisahkan rak penyimpanan, lokasi mesin (asal barang
        // jadi), dan area pengiriman. Tanpa tipe, sistem tidak bisa menentukan
        // lokasi asal dan tujuan pemindahan secara otomatis.
        Schema::table('locations', function (Blueprint $table) {
            $table->string('type', 20)->default('storage')->after('name')->index();
        });
    }

    public function down(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->dropIndex(['type']);
            $table->dropColumn('type');
        });
    }
};
