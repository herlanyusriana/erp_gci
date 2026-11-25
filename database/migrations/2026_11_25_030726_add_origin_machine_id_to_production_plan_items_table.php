<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('production_plan_items', function (Blueprint $table) {
            $table->foreignId('origin_machine_id')
                ->nullable()
                ->after('machine_id')
                ->constrained('machines')
                ->nullOnDelete();
            $table->index(
                ['production_plan_id', 'work_order_id', 'origin_machine_id'],
                'ppi_plan_wo_origin_idx',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('production_plan_items', function (Blueprint $table) {
            $table->dropIndex('ppi_plan_wo_origin_idx');
            $table->dropConstrainedForeignId('origin_machine_id');
        });
    }
};
