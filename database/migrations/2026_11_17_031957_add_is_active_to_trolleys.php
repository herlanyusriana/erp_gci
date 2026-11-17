<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Troli rusak/pensiun tidak dihapus (jejak pindai tetap terbaca), cukup
        // dinonaktifkan agar tidak muncul di pilihan dan tidak lolos validasi.
        Schema::table('trolleys', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('notes')->index();
        });
    }

    public function down(): void
    {
        Schema::table('trolleys', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });
    }
};
