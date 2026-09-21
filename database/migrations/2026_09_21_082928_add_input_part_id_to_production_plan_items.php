<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_plan_items', function (Blueprint $table) {
            $table->foreignId('input_part_id')->nullable()->after('wip_part_id')
                ->constrained('parts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('production_plan_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('input_part_id');
        });
    }
};
