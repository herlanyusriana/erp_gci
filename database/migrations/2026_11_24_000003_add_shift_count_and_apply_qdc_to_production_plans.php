<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_plan_machine_days', function (Blueprint $table) {
            $table->unsignedTinyInteger('shift_count')->default(3)->after('machine_id');
        });

        Schema::table('production_plan_items', function (Blueprint $table) {
            $table->boolean('apply_qdc')->default(false)->after('target_d2');
        });
    }

    public function down(): void
    {
        Schema::table('production_plan_items', function (Blueprint $table) {
            $table->dropColumn('apply_qdc');
        });

        Schema::table('production_plan_machine_days', function (Blueprint $table) {
            $table->dropColumn('shift_count');
        });
    }
};
