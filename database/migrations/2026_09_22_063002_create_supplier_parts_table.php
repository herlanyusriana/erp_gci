<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Daftar part per supplier (tanpa harga) — dipakai filter dropdown di PO.
        Schema::create('supplier_parts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained('suppliers')->cascadeOnDelete();
            $table->foreignId('part_id')->constrained('parts')->cascadeOnDelete();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['supplier_id', 'part_id']);
            $table->index(['part_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_parts');
    }
};
