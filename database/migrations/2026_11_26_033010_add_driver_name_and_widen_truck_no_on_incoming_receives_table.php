<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incoming_receives', function (Blueprint $table) {
            $table->text('truck_no')->nullable()->change();
            $table->text('driver_name')->nullable()->after('truck_no');
        });
    }

    public function down(): void
    {
        Schema::table('incoming_receives', function (Blueprint $table) {
            $table->dropColumn('driver_name');
            $table->string('truck_no', 255)->nullable()->change();
        });
    }
};
