<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Dokumen pengiriman barang jadi. Satu dokumen boleh berisi beberapa
        // tag FG; setiap baris menunjuk tepat satu tag stok.
        Schema::create('deliveries', function (Blueprint $table) {
            $table->id();
            $table->string('delivery_no', 40)->unique();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->date('delivery_date');
            $table->string('status', 20)->default('draft')->index();
            // Disiapkan untuk fase invoice; belum dipakai.
            $table->string('invoice_no')->nullable();
            $table->string('truck_no', 50)->nullable();
            $table->string('driver_name')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('delivery_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_id')->constrained('deliveries')->cascadeOnDelete();
            $table->foreignId('part_stock_id')->constrained('part_stocks')->restrictOnDelete();
            $table->foreignId('part_id')->constrained('parts')->restrictOnDelete();
            $table->string('tag')->nullable();
            $table->decimal('qty', 20, 4);
            $table->string('qty_unit', 20)->nullable();
            $table->string('origin_location_code', 40)->nullable();
            // Disiapkan untuk fase invoice; belum dipakai.
            $table->decimal('price', 20, 4)->nullable();
            $table->decimal('total_price', 20, 4)->nullable();
            $table->string('notes')->nullable();
            $table->timestamps();

            // Satu tag hanya boleh muncul sekali dalam satu dokumen.
            $table->unique(['delivery_id', 'part_stock_id']);
            $table->index(['part_stock_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_items');
        Schema::dropIfExists('deliveries');
    }
};
