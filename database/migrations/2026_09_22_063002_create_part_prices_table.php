<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Price master: harga per supplier × part, berlaku sejak `valid_from`.
        Schema::create('part_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained('suppliers')->cascadeOnDelete();
            $table->foreignId('part_id')->constrained('parts')->cascadeOnDelete();
            $table->decimal('price', 20, 4);
            $table->string('currency', 10)->default('IDR');
            $table->date('valid_from');
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['supplier_id', 'part_id', 'valid_from']);
            $table->index(['part_id', 'valid_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('part_prices');
    }
};
