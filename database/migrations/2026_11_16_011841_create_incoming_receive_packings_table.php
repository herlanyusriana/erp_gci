<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incoming_receive_packings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('receive_id')->constrained('incoming_receives')->cascadeOnDelete();
            $table->string('packing_no', 100)->index();
            $table->unsignedInteger('sequence')->default(1);
            $table->decimal('qty', 20, 4);
            $table->string('uom', 20);
            $table->decimal('net_weight', 20, 4)->nullable();
            $table->decimal('gross_weight', 20, 4)->nullable();
            $table->timestamps();

            $table->index(['receive_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incoming_receive_packings');
    }
};
