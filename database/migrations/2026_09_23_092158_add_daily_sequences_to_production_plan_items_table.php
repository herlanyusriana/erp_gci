<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_plan_items', function (Blueprint $table) {
            $table->unsignedInteger('sequence_d')->default(0)->after('sequence');
            $table->unsignedInteger('sequence_d1')->default(0)->after('sequence_d');
            $table->unsignedInteger('sequence_d2')->default(0)->after('sequence_d1');
        });
    }

    public function down(): void
    {
        Schema::table('production_plan_items', function (Blueprint $table) {
            $table->dropColumn(['sequence_d', 'sequence_d1', 'sequence_d2']);
        });
    }
};
