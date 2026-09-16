<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bom_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bom_id')->constrained('boms')->cascadeOnDelete();
            $table->unsignedInteger('sequence')->nullable();
            $table->foreignId('process_id')->nullable()->constrained('processes')->nullOnDelete();
            $table->foreignId('machine_id')->nullable()->constrained('machines')->nullOnDelete();
            $table->foreignId('parent_part_id')->nullable()->constrained('parts')->nullOnDelete();
            $table->string('parent_part_name')->nullable();
            $table->decimal('parent_qty', 18, 6)->nullable();
            $table->string('parent_uom')->nullable();
            $table->foreignId('child_part_id')->nullable()->constrained('parts')->nullOnDelete();
            $table->string('child_part_name')->nullable();
            $table->string('size')->nullable();
            $table->decimal('child_qty', 18, 6)->nullable();
            $table->string('uom_rm')->nullable();
            $table->string('special_code')->nullable();
            $table->string('source')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->index(['bom_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bom_items');
    }
};