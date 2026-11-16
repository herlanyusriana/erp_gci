<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Picking list harian: satu tanggal, banyak seq (urutan ambil), banyak
        // customer per baris. Tanpa tag FG — tag baru ditentukan saat operator
        // memindai barang di APK.
        Schema::create('picking_lists', function (Blueprint $table) {
            $table->id();
            $table->string('picking_no', 40)->unique();
            $table->date('picking_date');
            $table->string('status', 20)->default('draft')->index();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('picking_list_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('picking_list_id')->constrained('picking_lists')->cascadeOnDelete();
            $table->unsignedInteger('sequence')->default(1);
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignId('part_id')->constrained('parts')->restrictOnDelete();
            $table->decimal('qty', 20, 4);
            $table->string('qty_unit', 20)->nullable();
            // Snapshot hasil cek stok saat picking dibuat.
            $table->decimal('stock_available', 20, 4)->nullable();
            $table->decimal('stock_shortage', 20, 4)->nullable();
            $table->string('stock_status', 20)->default('ok');
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->index(['picking_list_id', 'sequence']);
            $table->index(['customer_id', 'part_id']);
        });

        // Baris delivery menjadi baris rencana (part + qty), bukan baris tag.
        // Tag hasil pindai tetap dicatat di delivery_scans saat barang dipindah.
        Schema::table('delivery_items', function (Blueprint $table) {
            $table->unsignedBigInteger('part_stock_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('delivery_items', function (Blueprint $table) {
            $table->unsignedBigInteger('part_stock_id')->nullable(false)->change();
        });

        Schema::dropIfExists('picking_list_items');
        Schema::dropIfExists('picking_lists');
    }
};
