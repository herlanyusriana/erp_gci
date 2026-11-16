<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Harga jual barang jadi per customer. Satu part hanya punya satu harga
        // aktif per customer; dipakai saat picking menghasilkan pengiriman.
        Schema::create('customer_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('part_id')->constrained('parts')->cascadeOnDelete();
            $table->decimal('price', 20, 4);
            $table->string('currency', 10)->default('IDR');
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['customer_id', 'part_id']);
            $table->index(['part_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_prices');
    }
};
