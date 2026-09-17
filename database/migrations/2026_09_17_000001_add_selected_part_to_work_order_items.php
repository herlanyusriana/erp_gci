<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('work_order_items', function (Blueprint $table) {
            $table->foreignId('selected_part_id')->nullable()->after('child_part_id')->constrained('parts')->nullOnDelete();
            $table->index('selected_part_id');
        });
    }

    public function down(): void
    {
        Schema::table('work_order_items', function (Blueprint $table) {
            $table->dropForeign(['selected_part_id']);
            $table->dropIndex(['selected_part_id']);
            $table->dropColumn('selected_part_id');
        });
    }
};