<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('machine_cycle_times', function (Blueprint $table) {
            $table->decimal('qdc_minutes', 8, 2)->nullable()->after('cycle_time_seconds');
        });
    }

    public function down(): void
    {
        Schema::table('machine_cycle_times', function (Blueprint $table) {
            $table->dropColumn('qdc_minutes');
        });
    }
};
