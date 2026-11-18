<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Keterangan bebas pada kedatangan impor.
     *
     * Dipakai untuk catatan yang tidak masuk kolom terstruktur (mis. informasi
     * rekening bank supplier). Dipisahkan dari `notes` karena `notes` sudah
     * dipakai sebagai catatan umum kedatangan lokal.
     */
    public function up(): void
    {
        Schema::table('incoming_arrivals', function (Blueprint $table) {
            if (! Schema::hasColumn('incoming_arrivals', 'remarks')) {
                $table->text('remarks')->nullable()->after('notes');
            }
        });
    }

    public function down(): void
    {
        Schema::table('incoming_arrivals', function (Blueprint $table) {
            if (Schema::hasColumn('incoming_arrivals', 'remarks')) {
                $table->dropColumn('remarks');
            }
        });
    }
};
