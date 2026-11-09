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
        Schema::table('incoming_receives', function (Blueprint $table) {
            $table->decimal('bundle_count', 20, 4)->nullable()->after('qty_unit');
            $table->decimal('packing_count', 20, 4)->nullable()->after('bundle_unit');
            $table->string('packing_type', 50)->nullable()->after('packing_count');
            $table->decimal('packing_qty', 20, 4)->nullable()->after('packing_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('incoming_receives', function (Blueprint $table) {
            $table->dropColumn(['bundle_count', 'packing_count', 'packing_type', 'packing_qty']);
        });
    }
};
