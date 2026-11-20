<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incoming_arrivals', function (Blueprint $table) {
            if (! Schema::hasColumn('incoming_arrivals', 'aju_date')) {
                $table->date('aju_date')->nullable()->after('aju_no');
            }
        });
    }

    public function down(): void
    {
        Schema::table('incoming_arrivals', function (Blueprint $table) {
            if (Schema::hasColumn('incoming_arrivals', 'aju_date')) {
                $table->dropColumn('aju_date');
            }
        });
    }
};
