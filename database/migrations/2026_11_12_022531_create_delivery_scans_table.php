<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Bukti pemuatan barang ke truk: operator memindai label troli dan
        // label FG hasil produksi. Tanpa bukti ini tahap loading tidak boleh
        // dirilis.
        Schema::create('delivery_scans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_id')->constrained('deliveries')->cascadeOnDelete();
            $table->string('scan_type', 20);
            $table->string('code');
            $table->foreignId('part_stock_id')->nullable()->constrained('part_stocks')->nullOnDelete();
            $table->unsignedBigInteger('scanned_by')->nullable();
            $table->timestamp('scanned_at');
            $table->timestamps();

            // Satu kode hanya dihitung sekali per dokumen.
            $table->unique(['delivery_id', 'scan_type', 'code']);
            $table->index(['delivery_id', 'scan_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_scans');
    }
};
