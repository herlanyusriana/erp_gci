<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parts', function (Blueprint $table) {
            $table->id();
            $table->string('part_number')->unique();
            $table->string('part_name');
            $table->foreignId('part_type_id')->nullable()->constrained('part_types')->nullOnDelete();
            $table->string('model')->nullable();
            $table->foreignId('uom_id')->nullable()->constrained('uoms')->nullOnDelete();
            $table->string('size')->nullable();
            $table->decimal('nett_weight', 18, 6)->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('remarks')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['part_type_id']);
            $table->index(['is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parts');
    }
};