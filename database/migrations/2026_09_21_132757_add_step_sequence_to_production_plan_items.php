<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_plan_items', function (Blueprint $table) {
            // Urutan step routing (dari WO) — dasar urutan grup mesin di papan.
            $table->unsignedInteger('step_sequence')->nullable()->after('sequence');
            $table->index(['production_plan_id', 'step_sequence']);
        });
    }

    public function down(): void
    {
        Schema::table('production_plan_items', function (Blueprint $table) {
            $table->dropIndex(['production_plan_id', 'step_sequence']);
            $table->dropColumn('step_sequence');
        });
    }
};
