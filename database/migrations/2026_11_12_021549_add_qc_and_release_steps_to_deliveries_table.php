<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Alur pengiriman: DO (picking qty) -> pindah ke area pengiriman ->
        // QC -> label & loading -> rilis dokumen (surat jalan, invoice,
        // packing list). Setiap tahap menyimpan waktu & pelakunya.
        Schema::table('deliveries', function (Blueprint $table) {
            $table->string('qc_status', 20)->default('pending')->after('status');
            $table->text('qc_notes')->nullable()->after('qc_status');
            $table->timestamp('qc_checked_at')->nullable()->after('qc_notes');
            $table->unsignedBigInteger('qc_checked_by')->nullable()->after('qc_checked_at');
            $table->timestamp('loaded_at')->nullable()->after('picked_at');
            $table->timestamp('released_at')->nullable()->after('loaded_at');
        });

        // `shipped` diganti `released`: barang baru dianggap selesai setelah
        // dokumen (surat jalan/invoice/packing list) dirilis.
        DB::table('deliveries')
            ->where('status', 'shipped')
            ->update([
                'status' => 'released',
                'released_at' => DB::raw('shipped_at'),
            ]);
    }

    public function down(): void
    {
        DB::table('deliveries')
            ->where('status', 'released')
            ->update([
                'status' => 'shipped',
                'shipped_at' => DB::raw('released_at'),
            ]);

        Schema::table('deliveries', function (Blueprint $table) {
            $table->dropColumn([
                'qc_status', 'qc_notes', 'qc_checked_at', 'qc_checked_by',
                'loaded_at', 'released_at',
            ]);
        });
    }
};
