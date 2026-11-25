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
        Schema::table('production_plan_machine_days', function (Blueprint $table) {
            $table->boolean('sync_downstream')->default(true)->after('initial_qdc');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('production_plan_machine_days', function (Blueprint $table) {
            $table->dropColumn('sync_downstream');
        });
    }
};
