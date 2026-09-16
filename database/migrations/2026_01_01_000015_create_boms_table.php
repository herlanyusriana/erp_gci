<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('boms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('part_id')->unique()->constrained('parts')->cascadeOnDelete();
            $table->unsignedInteger('bom_no')->nullable();
            $table->string('version')->default('1.0')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('boms');
    }
};