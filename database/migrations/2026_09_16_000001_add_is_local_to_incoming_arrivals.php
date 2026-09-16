<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incoming_arrivals', function (Blueprint $table) {
            $table->boolean('is_local')->default(false)->after('purchase_order_id');
            $table->index(['is_local']);
        });
    }

    public function down(): void
    {
        Schema::table('incoming_arrivals', function (Blueprint $table) {
            $table->dropIndex(['is_local']);
            $table->dropColumn('is_local');
        });
    }
};
